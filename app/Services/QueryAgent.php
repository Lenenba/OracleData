<?php

namespace App\Services;

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
     * Nombre maximum d'allers-retours avec le modèle (garde-fou anti-boucle).
     */
    protected int $maxIterations = 8;

    public function __construct(
        protected ClaudeClient $claude,
        protected OracleQueryTool $tool,
        protected OracleResourceCatalog $catalog,
        protected SemanticCatalogReader $semanticCatalog,
    ) {}

    /**
     * @return array{columns: list<string>, rows: array<int, mixed>, analysis: string, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}
     */
    public function run(string $tenantKey, string $intent): array
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = [['role' => 'user', 'content' => $intent]];
        $oracleCalls = [];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
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
                    return $this->finalResult($block['input'] ?? [], $oracleCalls);
                }

                if ($block['name'] === 'oracle_query') {
                    [$call, $toolResult] = $this->runOracleTool((string) $block['id'], (array) ($block['input'] ?? []), $tenantKey);
                    $oracleCalls[] = $call;
                    $toolResults[] = $toolResult;
                }
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
     * @param  array<string, mixed>  $input
     * @param  list<array{resource: string, params: array<string, mixed>, count: int}>  $oracleCalls
     * @return array{columns: list<string>, rows: array<int, mixed>, analysis: string, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}
     */
    protected function finalResult(array $input, array $oracleCalls): array
    {
        /** @var list<string> $columns */
        $columns = array_values(array_map('strval', (array) ($input['columns'] ?? [])));

        return [
            'columns' => $columns,
            'rows' => array_values((array) ($input['rows'] ?? [])),
            'analysis' => (string) ($input['analysis'] ?? ''),
            'oracleCalls' => $oracleCalls,
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
                'description' => 'Renvoie le résultat final composé (tableau + analyse) une fois toutes les lectures faites.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'analysis' => ['type' => 'string'],
                    ],
                    'required' => ['columns', 'rows'],
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
Tu es un analyste de données Oracle Fusion (lecture seule). À partir d'une demande en français,
tu lis les ressources nécessaires avec l'outil `oracle_query`, tu joins/agrèges les données toi-même,
puis tu renvoies le résultat final avec `submit_result`.

Ressources disponibles (n'utilise QUE ces ressources, champs et enfants) :
{$catalogContext}

Règles :
- Lecture seule (GET). Appelle `oracle_query` autant de fois que nécessaire.
- Pour lier deux ressources, récupère-les puis joins-les sur leur clé commune (ex : SupplierId).
- Termine TOUJOURS par `submit_result` avec des colonnes, des lignes et une courte analyse.
PROMPT;
    }
}
