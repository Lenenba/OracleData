<?php

namespace App\Services;

use App\Exceptions\AgentAnalysisCancelled;
use App\Models\User;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Agent d'analyse multi-ressources (Niveau B).
 *
 * Donne au LLM deux outils — `oracle_query` (lecture Oracle, garde-fous via
 * {@see OracleQueryTool}) et `submit_result` (rendu final) — et déroule la
 * boucle tool-use jusqu'au résultat composé. Lecture seule ; les identifiants
 * du tenant restent côté serveur.
 */
class QueryAgent
{
    /**
     * Lot 11A — Nombre maximum d'allers-retours avec le modèle (lu depuis config,
     * surchargeable par instance pour les tests ou des contextes spécifiques).
     */
    protected int $maxIterations;

    public function __construct(
        protected ClaudeClient $claude,
        protected OracleQueryTool $tool,
        protected OracleResourceCatalog $catalog,
        protected SemanticCatalogReader $semanticCatalog,
    ) {
        $this->maxIterations = max(1, (int) config('services.anthropic.max_iterations', 8));
    }

    /**
     * Return an agent bound to a single user, for background and explicit-user
     * flows that cannot rely on the current authentication guard.
     */
    public function forUser(User|int $user): self
    {
        $scoped = clone $this;
        $scoped->tool = $this->tool->forUser($user);

        return $scoped;
    }

    /**
     * @param  Closure(int $iteration, int $oracleCalls): void|null  $onProgress  Called after each completed iteration.
     * @param  Closure(): bool|null  $shouldCancel  Checked before each iteration; a truthy return aborts the run.
     * @return array{columns: list<string>, rows: array<int, mixed>, analysis: string, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}
     *
     * @throws AgentAnalysisCancelled when a cooperative cancellation is requested mid-run.
     */
    public function run(
        string $tenantKey,
        string $intent,
        ?Closure $onProgress = null,
        ?Closure $shouldCancel = null,
    ): array {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = [['role' => 'user', 'content' => $intent]];
        $oracleCalls = [];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            if ($shouldCancel !== null && $shouldCancel()) {
                throw new AgentAnalysisCancelled;
            }

            $response = $this->claude->messages([
                'max_tokens' => 4096,
                'system' => $this->systemPrompt(),
                'tools' => $this->tools(),
                'messages' => $messages,
            ]);

            /** @var array<int, array<string, mixed>> $content */
            $content = $response['content'] ?? [];
            $toolResults = [];

            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                if ($block['name'] === 'submit_result') {
                    if ($onProgress !== null) {
                        $onProgress($iteration + 1, count($oracleCalls));
                    }

                    return $this->finalResult($block['input'] ?? [], $oracleCalls);
                }

                if ($block['name'] === 'oracle_query') {
                    [$call, $toolResult] = $this->runOracleTool((string) $block['id'], (array) ($block['input'] ?? []), $tenantKey);
                    $oracleCalls[] = $call;
                    $toolResults[] = $toolResult;
                }
            }

            if ($onProgress !== null) {
                $onProgress($iteration + 1, count($oracleCalls));
            }

            if ($toolResults === []) {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        throw new RuntimeException("L'analyse n'a pas abouti à un résultat exploitable.");
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array{resource: string, params: array<string, mixed>, count: int}, 1: array<string, mixed>}
     */
    protected function runOracleTool(string $toolUseId, array $input, string $tenantKey): array
    {
        try {
            $result = $this->tool->run($tenantKey, $input);

            $call = [
                'resource' => (string) ($input['resource'] ?? ''),
                'params' => $result['params'],
                'count' => $result['count'],
            ];

            $toolResult = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseId,
                'content' => json_encode([
                    'count' => $result['count'],
                    'hasMore' => $result['hasMore'],
                    'items' => $result['items'],
                ]),
            ];

            return [$call, $toolResult];
        } catch (InvalidArgumentException $e) {
            return [
                ['resource' => (string) ($input['resource'] ?? ''), 'params' => $input, 'count' => 0],
                ['type' => 'tool_result', 'tool_use_id' => $toolUseId, 'is_error' => true, 'content' => $e->getMessage()],
            ];
        }
    }

    /**
     * Lot 11B — résultat final enrichi avec confiance et provenance.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{resource: string, params: array<string, mixed>, count: int}>  $oracleCalls
     * @return array{columns: list<string>, rows: array<int, mixed>, analysis: string, confidence: string, sources_used: list<string>, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}
     */
    protected function finalResult(array $input, array $oracleCalls): array
    {
        /** @var list<string> $columns */
        $columns = array_values(array_map('strval', (array) ($input['columns'] ?? [])));

        // Confidence: high | medium | low — valeur fournie par le modèle ou
        // déduite du nombre de ressources interrogées si absente.
        $confidence = (string) ($input['confidence'] ?? '');

        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = count($oracleCalls) === 1 ? 'high' : (count($oracleCalls) <= 3 ? 'medium' : 'low');
        }

        // Sources : liste des clés de ressources Oracle effectivement interrogées.
        $sourcesFromInput = is_array($input['sources_used'] ?? null)
            ? array_values(array_map('strval', $input['sources_used']))
            : [];
        $sourcesFromCalls = array_values(array_unique(array_map(
            fn (array $call): string => (string) $call['resource'],
            $oracleCalls,
        )));

        $sourcesUsed = $sourcesFromInput !== [] ? $sourcesFromInput : $sourcesFromCalls;

        return [
            'columns'      => $columns,
            'rows'         => array_values((array) ($input['rows'] ?? [])),
            'analysis'     => (string) ($input['analysis'] ?? ''),
            'confidence'   => $confidence,
            'sources_used' => $sourcesUsed,
            'oracleCalls'  => $oracleCalls,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function tools(): array
    {
        return [
            [
                'name' => 'oracle_query',
                'description' => 'Lit une ressource Oracle Fusion (GET). À appeler pour récupérer des lignes, autant de fois que nécessaire.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'resource' => ['type' => 'string', 'description' => 'Clé de ressource du catalogue.'],
                        'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'q' => ['type' => 'string', 'description' => 'Filtre finder Oracle.'],
                        'orderBy' => ['type' => 'string'],
                        'expand' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'limit' => ['type' => 'integer'],
                        'offset' => ['type' => 'integer'],
                    ],
                    'required' => ['resource'],
                ],
            ],
            [
                'name' => 'submit_result',
                'description' => 'Renvoie le résultat final composé (tableau + analyse) une fois toutes les lectures faites. Inclure le niveau de confiance et les sources interrogées.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'analysis' => ['type' => 'string', 'description' => 'Courte analyse en une à trois phrases.'],
                        // Lot 11B — confiance et provenance.
                        'confidence' => [
                            'type' => 'string',
                            'enum' => ['high', 'medium', 'low'],
                            'description' => 'high = résultat complet et direct ; medium = agrégation partielle ou hypothèse ; low = approximation ou données manquantes.',
                        ],
                        'sources_used' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Clés de ressources Oracle utilisées pour produire ce résultat.',
                        ],
                    ],
                    'required' => ['columns', 'rows', 'analysis', 'confidence'],
                ],
            ],
        ];
    }

    protected function systemPrompt(): string
    {
        $catalogContext = $this->semanticCatalog->context(app()->getLocale());

        if ($catalogContext === '') {
            $catalogContext = $this->catalog->context();
        }

        return <<<PROMPT
Tu es un analyste de données Oracle Fusion (lecture seule). À partir d'une demande,
tu lis les ressources nécessaires avec l'outil `oracle_query`, tu joins/agrèges les données toi-même,
puis tu renvoies le résultat final avec `submit_result`.

Ressources disponibles (n'utilise QUE ces ressources, champs et enfants) :
{$catalogContext}

Règles :
- Lecture seule (GET). Appelle `oracle_query` autant de fois que nécessaire.
- Pour lier deux ressources, récupère-les puis joins-les sur leur clé commune (ex : SupplierId).
- Termine TOUJOURS par `submit_result` avec des colonnes, des lignes, une courte analyse et la confiance.
- Confiance (champ `confidence`) : "high" si le résultat couvre exactement la demande avec des données directes ; "medium" si une agrégation ou hypothèse est nécessaire ; "low" si des données manquent ou si la réponse est une approximation.
- Sources (champ `sources_used`) : liste les clés de ressources Oracle effectivement interrogées pour produire le résultat.
PROMPT;
    }
}
