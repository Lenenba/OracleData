# Design — Moteur de requêtes Oracle intelligent (LLM + garde-fous)

> Date : 2026-06-16
> Statut : design validé en brainstorming, en attente de revue de la spec
> Contexte : `docs/user-stories-bibliotheque-requetes.md` (v1 livrée : catalogue 2 ressources, matching mots-clés, `limit` seul)

## 1. Objectif

Rendre la bibliothèque de requêtes capable de comprendre des **demandes en langage naturel
complexes** et de **toujours renvoyer ce qui est demandé** — soit le résultat, soit une
question de clarification, jamais du vide silencieux.

Exemples cibles formulés par l'utilisateur :

- **A —** « liste des fournisseurs avec numéro, nom + leurs contacts et leurs sites »
- **B —** « lier les fournisseurs et les factures et analyser »

## 2. Décisions retenues (brainstorming)

| Décision | Choix |
|---|---|
| Moteur de traduction NL → requête | **LLM (API Claude) + garde-fous** : le LLM propose, le backend valide contre une liste blanche avant d'exécuter |
| Capacités | Filtres `q`, tri `orderBy`, sélection `fields`, enfants `expand`, catalogue de ressources élargi, **analyse multi-ressources** |
| Périmètre | **Niveau A + Niveau B**, sur une **fondation commune** (un seul outil de lecture Oracle) |
| Exécution agent (Niveau B) | **Synchrone + spinner** (asynchrone/queue = amélioration future) |
| Intégration Claude | **HTTP brut** via le client `Http` de Laravel (pas de SDK) |
| Modèle | `claude-opus-4-8`, thinking adaptatif |

## 3. Deux niveaux, une fondation

**Niveau A — une ressource enrichie (1 appel Oracle).**
Oracle Fusion REST imbrique les ressources enfants via `expand`. La demande A se traduit par un
seul GET : `fields=SupplierNumber,Supplier&expand=contacts,sites&q=...&orderBy=...&limit=...`.

**Niveau B — plusieurs ressources liées + analyse (N appels orchestrés).**
Oracle REST ne fait pas de jointure native entre ressources de premier niveau. La demande B
nécessite : récupérer les fournisseurs → récupérer les factures → **joindre** par `SupplierId`
→ **agréger/analyser**. C'est un travail d'agent.

**Fondation commune.** Un seul outil de lecture côté serveur, `oracle_query(resource, params)`
(GET seul). Le Niveau A = **un** appel de cet outil ; le Niveau B = l'agent qui l'appelle
**plusieurs fois**. Mêmes garde-fous dans les deux cas.

```
Demande NL ──▶ Résolveur LLM (1 appel, sortie structurée)
                   │
        ┌──────────┴───────────┐
   mode: single            mode: agent
   { resource, fields,     (analyse multi-ressources)
     q, orderBy, expand,        │
     limit }                    ▼
        │              Boucle agent Claude
        ▼              avec outil oracle_query (GET seul) + submit_result
   1 GET Oracle          N GET → jointure → agrégation → submit_result
        │                         │
        └──────────┬──────────────┘
                   ▼
   Résultats normalisés : { columns, rows, analysis?, oracleCalls[], clarification? }
```

## 4. Composants

### 4.1 `OracleResourceCatalog` (étendu)

Devient la **source unique de vérité** : contexte fourni au LLM **et** liste blanche de
validation **et** suggestions UI. Par ressource :

```
key, label, domain, path, keywords,
fields            : champs sélectionnables/affichables connus
filterable_fields : champs utilisables dans q
child_resources   : noms valides pour expand (ex. sites, contacts, addresses)
join_keys         : { invoices: SupplierId, ... } pour le Niveau B
```

Set initial ciblé (extensible) : fournisseurs (+ sites/contacts/adresses), employés, factures
AP, commandes PO, comptes. Les noms de champs/enfants exacts par ressource seront confirmés à
l'implémentation contre la doc Oracle 11.13.18.05.

### 4.2 `OracleQueryTool` (garde-fous)

Exécution serveur d'une requête Oracle structurée. Invariants :

- `resource` doit être une **clé du catalogue** ; sinon refus.
- `fields` / `q` / `orderBy` / `expand` validés contre les champs/enfants connus de la ressource
  (un champ inventé par le LLM est **rejeté**, pas envoyé à Oracle).
- `limit` bornée (réutilise `clampLimit`).
- Exécution via `FusionManager->tenant($key)->get($path, $params)` — **identifiants du tenant
  injectés côté serveur, jamais transmis au LLM**.
- **GET uniquement.** Aucune écriture possible par conception.

### 4.3 `ClaudeClient` (HTTP brut)

Service mince autour de `Http::withHeaders(...)->post('https://api.anthropic.com/v1/messages')`.

- En-têtes : `x-api-key: <ANTHROPIC_API_KEY>`, `anthropic-version: 2023-06-01`,
  `content-type: application/json`.
- Corps : `model=claude-opus-4-8`, `thinking={type:adaptive}`, `max_tokens` (résolveur ~2048,
  agent ~8000), `system`, `messages`, `tools` (agent), `output_config.format` (résolveur).
- Erreurs HTTP/réseau encapsulées en exception applicative avec message propre (jamais une 500
  brute remontée à l'UI).

### 4.4 `QueryResolver` (1 appel LLM, sortie structurée)

Entrée : intention NL + catalogue compact. Sortie structurée (`output_config.format` json_schema) :

```jsonc
// mode single
{ "mode": "single",
  "query": { "resource": "suppliers", "fields": ["SupplierNumber","Supplier"],
             "expand": ["contacts","sites"], "q": "...", "orderBy": "...", "limit": 25 } }
// mode agent
{ "mode": "agent", "plan_summary": "Joindre fournisseurs et factures, agréger le total facturé." }
// clarification
{ "mode": "clarify", "question": "De quel département parlez-vous ?" }
```

### 4.5 `QueryAgent` (boucle tool-use, Niveau B)

Boucle agentique manuelle (HTTP brut) avec **deux outils** :

- `oracle_query(resource, fields?, q?, orderBy?, expand?, limit?)` → exécuté par `OracleQueryTool`,
  résultat (items + métadonnées) renvoyé au modèle.
- `submit_result(columns, rows, analysis)` → termine la boucle et fixe le rendu final structuré.

Limites de sûreté : nombre maximum d'itérations/appels Oracle borné ; chaque appel journalisé
dans `oracleCalls[]` (transparence + débogage).

## 5. Sémantique d'enregistrement & exécution

| Mode | À l'enregistrement | À la ré-exécution (`run`) |
|---|---|---|
| **single** | LLM résolu **une fois** → on stocke `resource_path` + `parameters` (fields/q/orderBy/expand/limit) | **Simple GET, aucun appel LLM** → gratuit et rapide |
| **agent** | On stocke l'intention NL (`description`) + `mode=agent`, `resource_path=null` | **Boucle agent** (LLM + N GET) à chaque exécution |

Conséquence : le coût LLM est payé à la création pour le mode single, et à chaque run pour le
mode agent (c'est de l'analyse, par nature non figée).

## 6. Modèle de données (`Query`)

- `resource_path` → **nullable** (les requêtes agent n'ont pas de chemin unique).
- Nouvelle colonne `mode` : `single` | `agent` (défaut `single`).
- `parameters` (JSON) stocke désormais aussi `orderBy`, `expand`, `fields` (mode single).
- L'intention NL reste dans `description`.
- → **1 migration** (`add_mode_to_queries_table` + `resource_path` nullable).

## 7. Endpoints (adaptation de `QueryController`)

- `preview` : appelle `QueryResolver` ; si `single` exécute un GET d'aperçu (limit réduite),
  si `agent` lance une exécution agent bornée, si `clarify` renvoie la question. Réponse :
  `{ mode, query|plan, columns, rows, analysis?, oracleCalls[], clarification?, error? }`.
- `store` : persiste avec `mode` (résultat du résolveur).
- `show` : passe `mode` + dernier rendu.
- `run` : **branche sur `mode`** — single → `OracleQueryTool` (GET) ; agent → `QueryAgent`.

Validation : `RunQueryRequest` / `StoreQueryRequest` étendus (tenant + intent). Erreurs LLM/Oracle
toujours renvoyées en JSON propre (pattern `failedValidation` existant).

## 8. Frontend

- `results-table.tsx` : afficher les **enfants imbriqués** d'`expand` (sous-tableau dépliable).
- `query-form.tsx` : la résolution par mots-clés côté client est **supprimée** ; le formulaire
  envoie l'intention et affiche ce que renvoie l'aperçu serveur (mode, appels Oracle, clarification).
- `show.tsx` : afficher `mode`, les **appels Oracle effectués**, et l'**analyse** (mode agent) ;
  bloc « clarification » quand le LLM demande une précision.

## 9. Stratégie de test (Pest)

Aucun appel réseau réel : **Oracle** (`Http::fake()` enveloppe Oracle) **et Claude** (`Http::fake()`
réponses `/v1/messages`) sont simulés.

- Résolveur : « fournisseurs avec contacts et sites » → `single` + `expand` ; « lier fournisseurs
  et factures » → `agent` ; demande ambiguë → `clarify`.
- Garde-fous : ressource hors catalogue → rejetée ; champ inventé dans `fields`/`q` → rejeté ;
  toute tentative non-GET → impossible (l'outil ne l'expose pas).
- Niveau A : mode single mocké → GET avec `fields/expand/q/orderBy` corrects, table imbriquée.
- Niveau B : 2 ressources mockées (fournisseurs + factures) → agent joint sur `SupplierId` et
  agrège correctement ; `oracleCalls[]` reflète les appels.
- Run single ne déclenche **aucun** appel Claude (assertion `Http::assertNotSent` vers Anthropic).
- Multi-tenant : l'agent tape bien la `base_url` du tenant choisi.

## 10. Prérequis & config

- `ANTHROPIC_API_KEY` dans `.env` / `.env.example`.
- `config/services.php` : bloc `anthropic` (`api_key`, `model`, `base_url`).
- Pas de nouvelle dépendance Composer (HTTP brut).

## 11. Hors périmètre (v1)

- Écriture/modification dans Oracle (lecture seule conservée).
- Exécution asynchrone / file d'attente pour l'agent (synchrone d'abord).
- Restriction des tenants/ressources par utilisateur ou rôle.
- Pagination avancée au-delà de `limit`/`offset`.
- OTBI / BI Publisher.

## 12. Risques & limites assumées

- **Latence/coût (mode agent)** : plusieurs GET + raisonnement LLM par exécution. Atténué par
  un plafond d'itérations et le mode single sans LLM au run.
- **Jointures bornées** : les jointures Niveau B opèrent sur des volumes limités par `limit`,
  pas sur des millions de lignes.
- **Exactitude des champs Oracle** : le catalogue doit refléter les vrais noms de champs/enfants
  par ressource (à confirmer contre la doc 11.13.18.05 à l'implémentation).
```
