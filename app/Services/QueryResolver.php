<?php

namespace App\Services;

use RuntimeException;

/**
 * Traduit une demande en langage naturel en plan d'exécution, via le LLM.
 *
 * Sortie normalisée :
 *  - { mode: 'single', query: {...} } — une ressource (exécutable en un GET) ;
 *  - { mode: 'agent', plan: string }  — analyse multi-ressources (boucle agent) ;
 *  - { mode: 'clarify', question: string } — demande ambiguë.
 *
 * Le LLM ne voit jamais d'identifiants ; il ne propose que des ressources et
 * champs du catalogue, re-validés ensuite par {@see OracleQueryTool}.
 */
class QueryResolver
{
    public function __construct(
        protected ClaudeClient $claude,
        protected OracleResourceCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $intent, mixed $limit = null): array
    {
        $defaultLimit = $this->catalog->clampLimit($limit);

        $response = $this->claude->messages([
            'max_tokens' => 1024,
            'system' => $this->systemPrompt($defaultLimit),
            'messages' => [['role' => 'user', 'content' => $intent]],
        ]);

        $data = $this->decodeJson(ClaudeClient::textFrom($response));

        return $this->normalize($data, $defaultLimit);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data, int $defaultLimit): array
    {
        $mode = $data['mode'] ?? null;

        if ($mode === 'agent') {
            return ['mode' => 'agent', 'plan' => (string) ($data['plan'] ?? '')];
        }

        if ($mode === 'clarify') {
            return ['mode' => 'clarify', 'question' => (string) ($data['question'] ?? __('Pouvez-vous préciser votre demande ?'))];
        }

        if ($mode === 'single' && is_array($data['query'] ?? null) && ($data['query']['resource'] ?? '') !== '') {
            $query = $data['query'];
            $query['limit'] = $query['limit'] ?? $defaultLimit;

            return ['mode' => 'single', 'query' => $query];
        }

        throw new RuntimeException('Réponse du modèle inexploitable pour cette demande.');
    }

    /**
     * Extrait et décode le premier objet JSON présent dans la réponse.
     *
     * @return array<string, mixed>
     */
    protected function decodeJson(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('Réponse du modèle inexploitable pour cette demande.');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Réponse du modèle inexploitable pour cette demande.');
        }

        return $decoded;
    }

    protected function systemPrompt(int $defaultLimit): string
    {
        return <<<PROMPT
Tu traduis une demande utilisateur en français en un plan de lecture Oracle Fusion REST (GET uniquement, lecture seule).

Ressources disponibles (n'utilise QUE ces ressources, champs et enfants, jamais d'autres) :
{$this->catalog->context()}

Réponds STRICTEMENT par un seul objet JSON, sans texte autour, selon l'un de ces trois modes :

1. Une seule ressource suffit (filtres, tri, sélection de champs, enfants imbriqués) :
{"mode":"single","query":{"resource":"<clé>","fields":["..."],"q":"<finder Oracle>","orderBy":"Champ:asc","expand":["enfant"],"limit":<entier>,"offset":<entier>}}
- "fields", "q", "orderBy", "expand", "offset" sont optionnels ; n'inclus que ce qui est demandé.
- Données rattachées demandées (adresses, sites, contacts, lignes…) → mets les enfants dans "expand".
- Si l'utilisateur veut restreindre les colonnes ("juste le nom et le numéro"), mets ces champs parent dans "fields". Tu PEUX combiner "fields" (colonnes parent) et "expand" (enfants) : les deux sont pris en charge ensemble.
- "q" suit la syntaxe finder Oracle. Mets TOUJOURS les valeurs entre apostrophes, y compris les numéros identifiants car ce sont des chaînes : SupplierNumber='28784', Status='ACTIVE', Supplier LIKE '%Acme%'.
- Pour « le fournisseur 28784 » → q sur le numéro identifiant quoté, et expand des enfants demandés.
- limite par défaut : {$defaultLimit}.

2. La demande lie plusieurs ressources ou exige une analyse/agrégation :
{"mode":"agent","plan":"<résumé en une phrase de la marche à suivre>"}

3. La demande est trop ambiguë pour choisir une ressource :
{"mode":"clarify","question":"<question de clarification courte>"}
PROMPT;
    }
}
