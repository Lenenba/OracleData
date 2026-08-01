# Feuille de route — évolution du moteur de requêtes Oracle Fusion

## POC Workers et requêtes hiérarchiques

**État du document :** audit et plan d'évolution proposés le 30 juillet 2026

**Périmètre fonctionnel immédiat :** Oracle Fusion HCM, ressource `workers`

**Périmètre du premier jalon produit :** construire, enregistrer et rouvrir un Query Graph récursif jusqu'à `Managers`

**Périmètre du second jalon produit :** exécuter ce graphe et restituer un résultat hiérarchique

---

## 1. Résumé de la décision

L'application possède déjà la majorité des fondations à conserver : client Oracle centralisé, isolation des tenants, catalogue statique, catalogue sémantique gouverné, synchronisation `/describe`, découverte des champs, garde-fous d'exécution, audit, lignage et Query Builder React.

Un prototype non committé de `ResourceDefinition`, `RelationDefinition`, `QueryGraph`, `ExecutionContext`, registre Workers, endpoint `child-resources` et colonne `query_graph` est également présent dans le worktree. Ce prototype est une bonne base, mais il n'est pas encore relié au moteur réellement utilisé par l'application.

La suite ne doit donc pas être une réécriture. Elle doit :

1. fiabiliser les objets de domaine déjà commencés ;
2. éviter trois catalogues concurrents en créant un catalogue canonique alimenté par plusieurs providers ;
3. conserver `resource_path + parameters` pour toutes les requêtes historiques ;
4. brancher progressivement le `QueryGraph` sur la sauvegarde et le Query Builder ;
5. introduire ensuite un planner et une première stratégie d'exécution récursive ;
6. garder Workers comme métadonnées/overrides de référence, sans introduire de logique `if workers`, `if assignments` ou `if managers` dans le moteur.

La première tâche recommandée n'est pas de construire l'interface. Il faut d'abord stabiliser le domaine et définir une source canonique pour le Resource Graph.

---

## 2. Sources analysées et état de validation

Les deux notes jointes à la demande sont des doublons exacts. Elles décrivent le même objectif et le même ordre de priorité.

L'audit a porté notamment sur :

- le modèle `Query` et sa persistance ;
- le Query Builder React et son état frontend ;
- `OracleResourceCatalog` ;
- le catalogue sémantique et son système de publication ;
- `OracleFieldDiscovery` et le cache par tenant ;
- `OracleDescribeNormalizer` et `OracleSchemaSynchronizationService` ;
- `OracleQueryTool` ;
- `FusionManager` et `FusionClient` ;
- les aperçus, exécutions web, exports, schedules, API et lignage ;
- le prototype de Resource Graph et Query Graph présent dans le worktree ;
- les tests existants et nouveaux.

### Validation exécutée pendant l'audit initial

Une sélection de tests couvrant le moteur historique et le prototype a été exécutée :

- **143 tests réussis** ;
- **504 assertions réussies** ;
- aucune régression détectée sur les parcours ciblés.

Les tests couvraient notamment :

- création, mise à jour et exécution des requêtes existantes ;
- aperçu direct du Query Builder ;
- découverte des champs ;
- synchronisation `/describe` ;
- `OracleQueryTool` ;
- endpoint `child-resources` ;
- objets `ResourceDefinition`, `RelationDefinition`, `QueryNode` et `QueryGraph`.

Cette validation n'a révélé aucune régression sur les parcours ciblés. Elle ne prouve pas encore qu'un Query Graph peut être sauvegardé, rechargé puis exécuté : ces parcours ne sont pas implémentés et les tests de round-trip manquent.

La photographie prise avant la phase 0 était la suivante :

- **51/51 tests** propres au nouveau domaine et à `child-resources` passaient, avec **166 assertions** ;
- la suite PHP complète comptait **634 tests**, dont **627 réussissaient** et **7 présentaient un problème** — 4 échecs et 3 erreurs, pour **3 804 assertions** ;
- Pint en mode vérification signalait des écarts sur **16 fichiers** de la tranche ;
- PHPStan niveau 7 signalait **10 erreurs** sur la nouvelle tranche.

Ces sept problèmes initiaux concernaient le budget de requêtes du dashboard, plusieurs scénarios d'import et des collisions d'agrégats/throttle. Ils ont été reproduits et corrigés pendant la phase 0. La nouvelle référence globale verte est consignée dans le bilan d'exécution ci-dessous ; les seuls tests ciblés ne sont toujours pas considérés comme un feu vert CI.

---

## 3. Architecture réellement utilisée aujourd'hui

### 3.1 Flux principal

```text
QueryController
    ↓
OracleResourceCatalog ou SemanticCatalogReader
    ↓
QueryBuilder React
    ↓
direct-preview / run
    ↓
OracleQueryTool
    ↓
FusionManager
    ↓
FusionClient
    ↓
Oracle Fusion REST
```

### 3.2 Responsabilités existantes

| Composant | Responsabilité actuelle | Décision |
|---|---|---|
| `FusionClient` | Point HTTP unique vers Oracle, Basic Auth, timeout, retry et logs | Conserver |
| `FusionManager` | Résolution des connexions appartenant à l'utilisateur | Conserver |
| `OracleQueryTool` | Validation et exécution protégée d'une requête racine, expand et joins | Conserver comme chemin historique et adaptateur simple |
| `OracleResourceCatalog` | Catalogue statique, liste blanche et fallback | Conserver temporairement via un adaptateur |
| `SemanticCatalogReader` | Projection du catalogue publié vers le builder et l'exécution | Conserver et adapter au nouveau catalogue canonique |
| `OracleFieldDiscovery` | Sondage léger et cache des champs par tenant | Conserver |
| `OracleSchemaSynchronizationService` | Synchronisation d'un `/describe` racine | Étendre, sans réécrire |
| `OracleDescribeNormalizer` | Normalisation des attributs et empreinte stable | Étendre pour les métadonnées réellement disponibles |
| `SemanticLineageService` | Lignage d'une spécification plate | Étendre pour parcourir tous les nœuds du Query Graph |
| `QueryChainService` | Chaînage entre deux requêtes sauvegardées | Conserver séparé du graphe Oracle |
| `QueryBuilder` / `QueryConfigPanel` | Édition d'une ressource racine avec champs, filtres, expand et joins | Faire évoluer progressivement |
| `ResultsTable` | Affichage de structures imbriquées | Réutiliser |

### 3.3 Persistance actuelle

Une requête simple est sauvegardée principalement avec :

```text
queries.resource_path
queries.parameters
```

`parameters` contient aujourd'hui une spécification canonique plate :

```json
{
  "resource_key": "workers",
  "fields": "PersonNumber,DateOfBirth",
  "expand": "names,workRelationships.assignments.managers",
  "q": "PersonNumber=25773",
  "limit": 25
}
```

Ce format doit rester valide pendant toute la migration.

### 3.4 Frontend actuel

Le Query Builder maintient un état plat :

```text
resource
fields[]
expand[]
joins[]
childFields{}
filterRows[]
orderBy
limit
```

Les chemins profonds importés, par exemple `workRelationships.assignments.managers`, sont conservés comme chaînes `expand`, mais ne sont pas éditables comme un arbre. Les champs, filtres et paramètres ne sont pas propres à chaque niveau.

### 3.5 Exécution actuelle

`OracleQueryTool` sait :

- valider la ressource racine ;
- valider les champs de la racine ;
- produire un `expand` Oracle ;
- joindre une autre ressource racine par appels supplémentaires ;
- projeter des champs ;
- appliquer la gouvernance sémantique.

Il ne sait pas encore :

- construire un plan à partir d'un Query Graph ;
- donner des filtres et capacités différents à chaque nœud ;
- parcourir récursivement des collections enfants ;
- transporter un contexte distinct pour chaque ligne et chaque branche ;
- résoudre des identifiants provenant de plusieurs ancêtres ;
- assembler un résultat récursif à partir de plusieurs appels.

---

## 4. État du prototype déjà présent

### 4.1 Éléments disponibles

Le worktree contient déjà :

```text
app/Domain/Resource/
├── AncestorBinding.php
├── FieldDefinition.php
├── QueryCapabilities.php
├── RelationDefinition.php
├── RelationType.php
└── ResourceDefinition.php

app/Domain/Query/
├── ExecutionContext.php
├── QueryEdge.php
├── QueryGraph.php
├── QueryNode.php
└── QueryTraversalPolicy.php
```

Ainsi que :

- `app/Services/ResourceDefinitionRegistry.php` ;
- `app/Services/Workers/WorkersResourceRegistry.php` ;
- `GET /queries/child-resources` ;
- une migration nullable ajoutant `queries.query_graph` ;
- des tests de domaine et d'endpoint.

### 4.2 Ce qui est déjà bien orienté

- séparation conceptuelle entre Resource Graph et Query Graph ;
- relations typées ;
- bindings de plusieurs ancêtres ;
- nœud récursif au lieu de six niveaux codés en dur ;
- limite de profondeur exprimée par une policy ;
- colonne `query_graph` nullable, adaptée à une migration progressive ;
- registre générique consulté par l'endpoint ;
- absence de conditions Workers dans `QueryGraph`.

### 4.3 Ce qui bloque la poursuite immédiate

| Blocage | Conséquence |
|---|---|
| `ResourceDefinitionRegistry` dépend directement de `WorkersResourceRegistry` | Ajouter un module oblige encore à modifier le registre central |
| Trois sources de métadonnées coexistent | Risque de divergence entre catalogue statique, catalogue sémantique et nouveau registre |
| `ResourceDefinition::toLegacyArray()` n'expose pas réellement tout le contrat historique | Il manque notamment `path`, `method`, `preview_fields`, `child_fields`, `join_keys`, `fallbacks` et `sql` |
| `QueryGraph::fromArray()` ne restaure pas les enfants | Un graphe sauvegardé perdrait sa structure au rechargement |
| `nodeId` peut être vide et n'est pas unique | Édition, ciblage et validation fiables impossibles |
| `addChild()` ne valide pas la relation, le parent, la profondeur, les cycles ou doublons | Un payload client invalide peut produire un graphe incohérent |
| `canGrowDeeper()` utilise la profondeur maximale globale | Une branche profonde peut bloquer une autre branche encore courte |
| `AncestorBinding.depth` n'est pas utilisé | Sémantique ambiguë et faux sentiment de support |
| `ExecutionContext` est indexé seulement par `resourceId` | Ambigu si la même ressource apparaît deux fois ou dans plusieurs branches |
| `identifiers[]` et `FieldDefinition::isIdentifier` doublonnent la même information | Possibilité de définitions contradictoires |
| Le endpoint fait confiance à `depth` fourni par le navigateur | La limite de profondeur n'est pas garantie côté serveur |
| `maxDepth` est une constante et peut être relu depuis le JSON | Une règle serveur ne doit pas être contrôlée par le payload sauvegardé |
| `query_graph` n'est pas validé par `StoreQueryRequest` | La colonne existe mais le flux métier ne peut pas la sauvegarder correctement |
| `QueryController` n'envoie pas le graphe au frontend | Une requête hiérarchique ne peut pas être rouverte |
| Clonage, lignage, API, export et schedule ignorent le graphe | Comportements incohérents selon le point d'entrée |
| Aucun composant frontend n'appelle `child-resources` | « Add Child Query » n'existe pas encore dans l'interface |
| `OracleQueryTool` ignore `query_graph` | Aucune exécution hiérarchique réelle |

### 4.4 Couverture Workers encore incomplète

Le catalogue historique et le prototype ne racontent pas encore exactement la même hiérarchie. Le premier expose notamment `assignments` directement sous Workers, tandis que le prototype le place sous `workRelationships`. Des identifiants diffèrent également, par exemple `PersonId`/`workersUniqID`, `WorkRelationshipId`/`PeriodOfServiceId` et `AssignmentId`/`assignmentsUniqID`. Le catalogue canonique ne doit pas masquer ces écarts : les fixtures Oracle doivent permettre de choisir et documenter la représentation correcte.

Le prototype contient notamment :

```text
Workers
├── Addresses
├── Emails
├── Phones
├── Names
└── WorkRelationships
    └── Assignments
        ├── Managers
        ├── AllReports
        ├── GradeSteps
        ├── Representatives
        └── WorkMeasures
```

Il manque encore des ressources mentionnées dans le besoin, notamment `Contracts`, `AssignmentsDFF` et `AssignmentsEFF`. Elles ne doivent pas être ajoutées sur la seule base d'une hypothèse : les noms, chemins, identifiants et capacités doivent être confirmés par des fixtures Oracle réelles ou par un tenant de validation.

---

## 5. Principes d'architecture à figer

### 5.1 Une seule vue canonique du Resource Graph

Le moteur et le Query Builder doivent dépendre d'un contrat unique, par exemple :

```php
interface ResourceCatalog
{
    public function find(ResourceId $id, CatalogContext $context): ?ResourceDefinition;

    public function childrenOf(ResourceId $id, CatalogContext $context): array;
}
```

Le nom exact peut évoluer. La règle importante est que ce contrat ne dépende ni de Workers, ni d'un tableau particulier, ni d'Eloquent, ni d'Oracle HTTP.

### 5.2 Plusieurs sources, un merge déterministe

Le catalogue canonique sera alimenté par plusieurs providers :

```text
Catalogue sémantique publié
        +
Snapshots Oracle /describe
        +
Overrides techniques Workers
        +
Adaptateur du catalogue historique
        ↓
HybridResourceCatalog
        ↓
ResourceGraph canonique
```

Les sources n'ont pas la même autorité :

| Source | Autorité |
|---|---|
| Catalogue sémantique publié | Décide ce qui est autorisé, actif et visible |
| Métadonnées Oracle observées | Fournissent les faits techniques observables : champs, types et attributs disponibles |
| Overrides techniques | Fournissent uniquement ce que `/describe` ne permet pas de déterminer avec fiabilité : chemins, identifiants, bindings et exceptions |
| Catalogue historique | Fallback de compatibilité pendant la transition |

En cas de conflit critique sur un chemin, un identifiant ou un binding, le merge doit échouer de manière fermée et produire un diagnostic. Il ne doit pas choisir silencieusement une valeur.

Chaque valeur technique utile devrait exposer sa provenance :

```text
oracle_describe
semantic_catalog
manual_override
legacy_fallback
```

### 5.3 Resource Graph et Query Graph restent séparés

```text
Resource Graph = ce que le provider autorise
Query Graph    = ce que l'utilisateur demande
Execution Plan = comment la demande sera exécutée
```

Le Query Graph ne contient pas de client HTTP et ne choisit pas une stratégie `expand`, récursive ou batch.

### 5.4 QueryChain reste une fonctionnalité distincte

`QueryChainService` relie des requêtes sauvegardées indépendantes par extraction et injection d'une valeur. Il ne représente pas une navigation Oracle parent/enfant. Le transformer en Query Graph créerait un couplage incorrect.

Pendant le POC, une requête avec `query_graph != null` ne doit être proposée ni comme source ni comme cible d'une QueryChain. Un support ultérieur devra passer par `SavedQueryExecutor` et définir explicitement comment extraire une valeur d'un résultat hiérarchique ; `QueryChainService` ne doit jamais appeler son `resource_path` nul comme s'il s'agissait d'une requête simple.

### 5.5 Compatibilité ascendante explicite

La règle de routage recommandée est :

```text
mode == agent                         → exécuteur agent existant ; query_graph obligatoirement null
mode != agent et query_graph == null  → exécuteur Oracle historique
mode != agent et query_graph != null  → nouvel exécuteur de graphe
```

Aucune migration de masse des anciennes requêtes n'est nécessaire pour le POC. Le mode `agent` reste une famille distincte : lui donner implicitement la sémantique d'un graphe Oracle créerait un troisième comportement ambigu.

### 5.6 La policy vient du serveur

`maxDepth = 6` doit vivre dans une configuration serveur, par exemple :

```php
'query_graph' => [
    'resource_graph_enabled' => env('FUSION_RESOURCE_GRAPH_ENABLED', false),
    'authoring_enabled' => env('FUSION_QUERY_GRAPH_AUTHORING_ENABLED', false),
    'execution_enabled' => env('FUSION_QUERY_GRAPH_EXECUTION_ENABLED', false),
    'max_depth' => (int) env('FUSION_QUERY_GRAPH_MAX_DEPTH', 6),
    'max_nodes' => (int) env('FUSION_QUERY_GRAPH_MAX_NODES', 50),
],
```

Le frontend peut afficher cette valeur, mais ne la décide pas. Pour rester compatible avec le prototype, la convention est **1-based** : la racine est à la profondeur 1 et `maxDepth = 6` autorise au plus six nœuds sur une branche. La profondeur réelle est calculée depuis l'arbre et validée à la sauvegarde et à l'exécution. Les trois flags sont séparés parce que la lecture du catalogue, l'édition/persistance et l'exécution seront livrées à des moments différents.

### 5.7 Un contexte par branche d'exécution

Un tableau global indexé uniquement par `resourceId` n'est pas suffisamment générique. L'exécution doit transporter une chaîne de frames, liée à une instance de nœud et à une ligne parent :

```text
ExecutionFrame
├── nodeId
├── resourceId
├── row
├── identifiers
└── parentFrame
```

Ainsi, deux occurrences de la même ressource ou deux branches parallèles ne s'écrasent pas.

### 5.8 Les identifiants requis sont distincts des champs affichés

Le planner doit ajouter automatiquement les identifiants nécessaires à la navigation, même si l'utilisateur ne veut pas les afficher. Le `ResultAssembler` peut ensuite les retirer de la projection publique.

---

## 6. Architecture cible adaptée au dépôt

```text
┌──────────────────────── Métadonnées et gouvernance ────────────────────────┐
│ SemanticCatalogProvider                                                    │
│ OracleDescribeProvider                                                     │
│ WorkersOverrideProvider                                                    │
│ LegacyOracleCatalogAdapter                                                 │
└───────────────────────────────┬─────────────────────────────────────────────┘
                                ↓
                     HybridResourceCatalog
                                ↓
                         ResourceGraph
                                ↓
┌──────────────────────────── Construction ──────────────────────────────────┐
│ Query Builder → QueryGraphDraft → QueryGraphHydrator → QueryGraphValidator │
└───────────────────────────────┬─────────────────────────────────────────────┘
                                ↓
                       queries.query_graph
                                ↓
┌──────────────────────────── Exécution ─────────────────────────────────────┐
│ QueryPlanner → ExecutionPlan → ExecutionStrategy → ResultAssembler         │
└───────────────────────────────┬─────────────────────────────────────────────┘
                                ↓
                    OracleFusionProvider
                                ↓
                FusionManager / FusionClient existants
```

### 6.1 Domaine

À conserver et consolider :

```text
Domain/Resource
├── ResourceDefinition
├── RelationDefinition
├── FieldDefinition
├── QueryCapabilities
├── RelationType
└── AncestorBinding

Domain/Query
├── QueryGraph
├── QueryNode
├── QueryEdge
└── QueryTraversalPolicy

Domain/Execution
├── ExecutionContext / ExecutionFrame
├── ExecutionPlan
└── ExecutionStep
```

`ExecutionContext` peut être déplacé de `Domain/Query` vers `Domain/Execution` lorsqu'il sera réellement utilisé. Ce déplacement n'est pas indispensable avant la stabilisation fonctionnelle.

### 6.2 Application

Services applicatifs attendus à terme :

```text
Application/Catalog
├── ResourceCatalog
├── HybridResourceCatalog
└── ResourceDefinitionMerger

Application/Query
├── QueryGraphHydrator
├── QueryGraphValidator
├── QueryPlanner
├── SavedQueryExecutor
└── ResultAssembler
```

Les dossiers exacts peuvent rester sous `app/Services` pendant la transition. La responsabilité est plus importante que le nom du dossier.

### 6.3 Infrastructure Oracle

À terme :

```text
Infrastructure/OracleFusion
├── OracleFusionProvider
├── OracleMetadataProvider
└── OraclePathResolver
```

Ces adaptateurs doivent réutiliser `FusionManager` et `FusionClient`, pas les remplacer.

---

## 7. Contrats canoniques recommandés

### 7.1 ResourceDefinition

Le contrat doit au minimum représenter :

```text
id
name
module
apiVersion
collectionPath
itemPath
fields[]
identifierFields[]
capabilities
relations[]
provenance
```

Une seule propriété doit déterminer les identifiants. Il faut choisir entre `identifiers[]` et `FieldDefinition::isIdentifier`, puis dériver l'autre projection si nécessaire.

### 7.2 RelationDefinition

Le contrat doit valider :

- un `id` stable ;
- une source existante ;
- une cible existante ;
- un type connu ;
- un template de chemin sûr ;
- une source autorisée pour chaque placeholder : contexte de catalogue fiable ou donnée d'ancêtre ;
- aucun binding orphelin ;
- la provenance de la définition.

Les placeholders de plateforme comme `apiVersion` sont résolus depuis le `CatalogContext` construit côté serveur. Ils ne sont pas des `AncestorBinding`, car ils ne proviennent d'aucune ligne de résultat.

Exemple conceptuel :

```json
{
  "id": "hcm.workers.workRelationships.assignments.to.managers",
  "sourceId": "hcm.workers.workRelationships.assignments",
  "targetId": "hcm.workers.workRelationships.assignments.managers",
  "type": "CHILD",
  "pathTemplate": "/hcmRestApi/resources/{apiVersion}/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers",
  "contextBindings": [
    {
      "placeholder": "apiVersion",
      "contextKey": "apiVersion"
    }
  ],
  "bindings": [
    {
      "placeholder": "workersUniqID",
      "sourceResourceId": "hcm.workers",
      "sourceField": "workersUniqID"
    },
    {
      "placeholder": "PeriodOfServiceId",
      "sourceResourceId": "hcm.workers.workRelationships",
      "sourceField": "PeriodOfServiceId"
    },
    {
      "placeholder": "assignmentsUniqID",
      "sourceResourceId": "hcm.workers.workRelationships.assignments",
      "sourceField": "assignmentsUniqID"
    }
  ]
}
```

### 7.3 Query Graph persistant

Le format JSON doit être versionné et ne stocker que la demande utilisateur. Exemple indicatif :

```json
{
  "schemaVersion": 1,
  "catalogFingerprint": "sha256:9c6c…",
  "root": {
    "nodeId": "550e8400-e29b-41d4-a716-446655440000",
    "resourceId": "hcm.workers",
    "fields": ["PersonNumber"],
    "filters": [
      {
        "field": "PersonNumber",
        "operator": "=",
        "value": "10001"
      }
    ],
    "parameters": {
      "limit": 25
    },
    "children": [
      {
        "relationId": "hcm.workers.to.workRelationships",
        "node": {
          "nodeId": "550e8400-e29b-41d4-a716-446655440001",
          "resourceId": "hcm.workers.workRelationships",
          "fields": ["PeriodOfServiceId"],
          "filters": [],
          "parameters": {},
          "children": []
        }
      }
    ]
  }
}
```

Règles :

- `depth` est calculé, pas accepté comme autorité ;
- `parent` est reconstruit, pas dupliqué dans le JSON ;
- `maxDepth` vient de la configuration serveur ;
- les chemins Oracle ne sont pas copiés dans chaque nœud ;
- les relations sont référencées par ID et revalidées contre le Resource Graph ;
- les IDs de nœud sont non vides et uniques ;
- l'empreinte immuable du Resource Graph hybride permet de détecter une dérive ;
- l'empreinte enregistrée sert à l'audit et à la détection d'impact, jamais à réautoriser une ressource qui n'est plus publiée dans la liste blanche courante.

### 7.4 Execution Plan

Le premier planner peut produire des étapes simples :

```text
ExecutionStep
├── stepId
├── nodeId
├── resourceId
├── relationId
├── dependsOn[]
├── pathTemplate
├── compiledBindings[]
│   ├── placeholder
│   ├── sourceNodeId ou contextKey
│   ├── sourceField
│   └── encoding
├── requiredIdentifierFieldsByNode[]
├── compiledQueryParameters
├── effectivePageBudget
└── resultAttachmentKey
```

Le planner résout donc chaque binding logique du Resource Graph vers une instance `sourceNodeId` précise de la branche. Une simple liste de noms de champs ou un `resourceId` ne suffit pas lorsque la même ressource apparaît plusieurs fois.

Le plan ne fait aucun appel réseau. Il doit pouvoir être testé avec des métadonnées en mémoire.

### 7.5 Filtres structurés

Un filtre persistant référence un champ gouverné, un opérateur canonique et une valeur typée. Le catalogue définit les opérateurs autorisés par type ; par exemple, un opérateur de comparaison numérique n'est pas automatiquement valable pour une date ou une chaîne.

Le compilateur Oracle est le seul composant autorisé à produire `q`. Il échappe les valeurs, contrôle les groupes logiques et refuse les fragments libres. Les éventuelles expressions avancées historiques restent sur le chemin legacy tant qu'un équivalent structuré n'est pas défini.

---

## 8. Feuille de route détaillée

Les durées sont indicatives pour une personne connaissant le dépôt. Elles n'incluent pas le délai d'obtention d'un payload `/describe` réel ou d'un accès au tenant Oracle de validation.

### Phase 0 — Sécuriser le point de départ

**Durée indicative :** 0,5 à 2 jours, hors correction d'une anomalie globale complexe

**But :** rendre l'état actuel reproductible avant de prolonger le prototype.

#### Travaux

1. Isoler le chantier « query graph » des modifications non liées déjà présentes dans le worktree.
2. Reproduire isolément chacun des sept problèmes de la suite complète et obtenir une baseline verte. Si une correction doit être différée, isoler précisément le test et le ticket concernés ; ne jamais créer une exemption globale qui masquerait une huitième régression.
3. Corriger les écarts Pint et les erreurs PHPStan de la tranche avant de l'étendre.
4. Vérifier les workflows CI : les commandes de lint doivent être en mode vérification et `tsc --noEmit` doit faire partie des contrôles bloquants.
5. Retirer Xdebug du job CI s'il n'est pas utilisé, ou publier une couverture avec un seuil utile.
6. Ajouter un job ciblé sur le même moteur de base de données que la production pour les migrations JSON et les contraintes concernées.
7. Conserver un état de référence des tests ciblés et globaux.
8. Ajouter trois feature flags désactivés par défaut : Resource Graph en lecture, authoring/persistance du Query Graph et exécution du Query Graph.
9. Capturer et anonymiser des fixtures Oracle pour :
   - `workers/describe` ;
   - `workRelationships/describe` ou équivalent disponible ;
   - `assignments/describe` ;
   - `managers/describe` ;
   - une réponse de collection à chaque niveau.
10. Documenter la version d'API testée et le tenant de provenance sans stocker de secret ni de donnée personnelle.

#### Critère de sortie

- le moteur historique reste inchangé lorsque les flags sont désactivés ;
- les tests ciblés et la baseline globale sont verts, ou chaque anomalie globale préexistante est isolée et suivie ;
- Pint et PHPStan passent sur tous les fichiers du lot ;
- les fixtures nécessaires au développement hors tenant sont disponibles ou leur absence est explicitement enregistrée comme risque.

#### Bilan d'exécution — 30 juillet 2026

**Statut : phase 0 terminée, avec les réserves environnementales explicites ci-dessous.**

| # | Travail | État | Preuve ou réserve |
|---:|---|---|---|
| 1 | Isoler le chantier | Terminé par périmètre tracé | Le worktree était déjà modifié. Aucun `reset`, stash ou commit n'a été imposé aux changements de l'utilisateur. Le prototype préexistant (`app/Domain`, registre Workers, migration `query_graph`, contrôleur d'exécution et tests associés) a été conservé ; les corrections de baseline, la qualité, la CI, les flags et les fixtures forment le périmètre phase 0. |
| 2 | Résoudre les sept problèmes globaux | Terminé | Les causes ont été corrigées : budget du dashboard, contrat d'import Postman et agrégats journaliers SQLite/UTC. La suite globale finale est verte. |
| 3 | Pint et PHPStan | Terminé | Pint global passe. PHPStan niveau 7 passe avec **0 erreur**, sans baseline, ignore ou contournement de type. |
| 4 | Workflows CI bloquants | Terminé | Pint, Prettier, ESLint, `tsc --noEmit`, PHPStan, build et tests sont câblés en vérification. Les permissions sont en lecture et les dépendances Node utilisent `npm ci`. |
| 5 | Xdebug/couverture | Terminé | La couverture est désactivée dans les jobs qui ne la publient pas ; Xdebug n'est pas chargé inutilement. |
| 6 | Migrations sur le moteur de référence | Terminé avec réserve environnementale | Le dépôt et les environnements local/test déclarent SQLite comme moteur de référence. Le cycle `migrate:fresh → rollback → migrate` passe localement. La CI couvre aussi MySQL 8.4 et PostgreSQL 16. Aucun manifeste de déploiement ne permet d'identifier un autre moteur de production ; il faudra aligner cette matrice avant tout déploiement qui en choisirait un. |
| 7 | État de référence | Terminé | La référence finale reproductible est enregistrée ci-dessous. |
| 8 | Trois feature flags fermés | Terminé | Lecture du Resource Graph, authoring/persistance et exécution du Query Graph sont `false` par défaut. L'endpoint de lecture retourne 404 lorsque son flag est désactivé. |
| 9 | Fixtures Workers à quatre niveaux | Satisfait pour le développement hors tenant | Huit fixtures synthétiques couvrent les quatre `describe` et les quatre collections Workers → WorkRelationships → Assignments → Managers. |
| 10 | Version et provenance | Satisfait par risque enregistré | La version de ressource **11.13.18.05** et l'origine documentaire sont consignées. La capture tenant, la release trimestrielle, l'alias tenant et la version REST Framework restent volontairement `null` avec le statut `tenant-capture-pending`. |

La validation finale de l'état exact du worktree donne :

| Contrôle | Résultat |
|---|---|
| Suite PHP globale | **649/649 tests**, **4 001 assertions** |
| Pint global | Réussi |
| PHPStan niveau 7 | Réussi, **0 erreur** |
| ESLint | Réussi |
| Prettier | Réussi |
| TypeScript | Réussi avec `tsc --noEmit` |
| Build Vite | Réussi, **2 404 modules** |
| Syntaxe des workflows | Les deux YAML sont parsés sans erreur |
| Migrations SQLite | `fresh`, rollback de la dernière migration, puis migration réussis |

L'isolation est volontairement **logique et documentée**, car créer un commit, une branche ou un stash aurait pris possession de changements déjà présents dans le worktree. La baseline verte couvre donc précisément l'état combiné actuel. Avant la phase 1, cet état devra être figé par le propriétaire du dépôt dans le découpage Git de son choix.

Les fixtures se trouvent sous `tests/Fixtures/oracle/hcm/workers/11.13.18.05`. Elles suivent les contrats publics Oracle HCM, utilisent uniquement des identifiants synthétiques stables et un hôte `oracle.invalid`, et ne contiennent aucune donnée personnelle. Elles ne prétendent pas remplacer une capture anonymisée d'un tenant autorisé ; cette validation reste une condition de la phase 2.

Le build émet encore un avertissement non bloquant sur le chunk principal d'environ **528 kB** après minification. Le découpage du bundle est une optimisation ultérieure, sans incidence sur la sortie de la phase 0.

---

### Phase 1 — Stabiliser le domaine Resource Graph / Query Graph

**Durée indicative :** 1 à 2 jours

**But :** obtenir un graphe cohérent, sérialisable et indépendant de Workers.

#### Travaux

1. Ajouter d'abord un test de round-trip qui expose la perte actuelle des enfants :

   ```text
   Workers
   → WorkRelationships
   → Assignments
   → Managers
   ```

2. Corriger `QueryGraph::fromArray()` pour reconstruire depuis le payload original.
3. Introduire une fabrique d'IDs de nœud ou exiger des ULID/UUID valides.
4. Ajouter un validateur de graphe qui vérifie :
   - appartenance du parent au graphe ;
   - source de la relation = ressource du parent ;
   - cible de la relation = ressource de l'enfant ;
   - parent de l'enfant cohérent ;
   - profondeur calculée = profondeur du parent + 1 ;
   - unicité des IDs ;
   - absence de cycle structurel entre instances de nœuds ;
   - aucune répétition d'un même `relationId` parmi les enfants directs d'un parent pendant le POC, afin d'éviter une collision de clé de résultat ;
   - nombre maximal de nœuds ;
   - champs et paramètres supportés par la ressource.
5. Remplacer `canGrowDeeper()` global par une vérification portant sur le nœud ciblé.
6. Déplacer `maxDepth` dans la configuration et ignorer toute valeur supérieure venant du payload.
7. Choisir une seule source pour les identifiants de ressource.
8. Définir `AncestorBinding` comme une référence logique à une ressource/valeur ancêtre ; le planner devra la compiler vers le `sourceNodeId` exact de la branche. Supprimer `depth` si cette information relative est redondante, mais ne pas perdre l'identité de la source.
9. Faire échouer `RelationDefinition` si :
   - un placeholder n'a pas de binding ;
   - un binding ne correspond à aucun placeholder ;
   - un placeholder demeure après résolution.
10. Définir un contrat de filtre structuré : champs gouvernés, opérateurs en liste blanche par type, valeurs validées et combinatoires logiques bornées. Le Query Graph ne doit pas accepter une chaîne Oracle `q` arbitraire.
11. Repenser `ExecutionContext` comme une chaîne de frames par branche, même si l'exécution ne l'utilise pas encore.

Un cycle signifie qu'une instance `nodeId` est réintroduite dans sa propre descendance ou qu'une référence structurelle revient vers un ancêtre. Réutiliser le même `resourceId` dans deux branches distinctes reste autorisé : cette répétition peut être métier et demeure bornée par `maxDepth` et `maxNodes`. Deux occurrences de la même relation sous un même parent sont interdites pendant le POC ; un futur `outputAlias` unique pourra lever cette restriction sans collision dans le résultat.

#### Fichiers principalement concernés

- `app/Domain/Query/QueryGraph.php` ;
- `app/Domain/Query/QueryNode.php` ;
- `app/Domain/Query/QueryTraversalPolicy.php` ;
- `app/Domain/Query/ExecutionContext.php` ;
- `app/Domain/Resource/ResourceDefinition.php` ;
- `app/Domain/Resource/RelationDefinition.php` ;
- `app/Domain/Resource/AncestorBinding.php` ;
- `config/fusion.php` ;
- `tests/Unit/Domain/*`.

#### Critère de sortie

- un graphe arbitraire valide peut faire `toArray() → fromArray() → toArray()` sans perte ;
- un payload ne peut pas falsifier sa profondeur ou sa relation ;
- un champ, un opérateur ou une valeur de filtre incompatible est rejeté ;
- deux branches de profondeurs différentes peuvent évoluer indépendamment ;
- aucun nom Workers n'apparaît dans la logique générique.

---

### Phase 2 — Construire le catalogue canonique hybride

**Durée indicative :** 3 à 5 jours

**But :** supprimer le risque de trois sources de vérité concurrentes.

#### Travaux

1. Introduire un contrat `ResourceCatalog` et un contrat `ResourceDefinitionProvider`.
2. Retirer la dépendance directe de `ResourceDefinitionRegistry` vers `WorkersResourceRegistry`.
3. Créer des providers/adaptateurs pour :
   - le catalogue sémantique publié ;
   - les snapshots `/describe` ;
   - les overrides techniques Workers ;
   - le catalogue historique comme fallback temporaire.
4. Introduire une table de correspondance d'identités stable entre les clés historiques (`workers`), les IDs sémantiques et les IDs canoniques (`hcm.workers`) ; refuser tout alias ambigu.
5. Introduire un merge déterministe avec provenance.
6. Définir un `CatalogContext` contenant au minimum :
   - tenant Oracle ;
   - famille d'API ;
   - version d'API ;
   - version du catalogue sémantique ;
   - locale pour les labels, hors cœur technique.
7. Construire ce contexte côté serveur à partir d'une connexion Oracle accessible à l'utilisateur. Ne jamais accepter du navigateur un tenant ou une version arbitraire comme autorité.
8. Étendre le modèle sémantique, son schéma versionné et son workflow de publication afin de représenter des nœuds et relations imbriqués avec `source_resource_id` et `target_resource_id` réels. Une relation profonde ne doit plus être réduite à un simple `expand` avec cible nulle.
9. Faire du catalogue sémantique publié la frontière d'autorisation. Supprimer la cascade actuelle où synchronisation, enrichissement et capture peuvent remplacer automatiquement la version publiée : une observation `/describe` provenant d'un tenant reste un fait isolé et nécessite une promotion gouvernée explicite.
10. Échouer de manière fermée pour l'authoring et l'exécution de graphe lorsqu'aucune version sémantique n'est publiée. Un bootstrap éventuel crée seulement un brouillon administratif à réviser et publier ; il n'autorise jamais directement un graphe. Le moteur historique conserve son comportement actuel.
11. Produire une empreinte immuable du Resource Graph hybride à partir de la version sémantique publiée, des IDs/empreintes de snapshots par tenant et version d'API, de la version des overrides et de celle de l'adaptateur historique.
12. Faire évoluer le schéma des snapshots pour inclure famille d'API, version d'API, ressource ou chemin enfant, tenant et empreinte. Rendre chaque snapshot utilisé par le merge immuable et adressable ; une simple « dernière observation » mutable n'est pas une version de catalogue.
13. Étendre dès cette phase la capture/synchronisation minimale des `/describe` enfants nécessaires au POC. Si Oracle ne les expose pas, marquer explicitement les propriétés concernées comme overrides validés par fixture plutôt que comme découverte runtime.
14. Étendre la découverte uniquement à partir de chemins racines autorisés.
15. Valider les paths, identifiants, bindings et capacités Workers contre les fixtures Oracle. En particulier, une collection enfant déclarée sans `limit`/`offset` doit tout de même avoir une borne serveur connue et une sémantique de pagination explicite, sinon elle reste non exécutable.
16. Transformer `WorkersResourceRegistry` en provider d'overrides ou en fichier de métadonnées, pas en catalogue complet concurrent.
17. Ajouter `Contracts`, `AssignmentsDFF` et `AssignmentsEFF` seulement après validation de leurs métadonnées.
18. Remplacer le endpoint actuel par un contrôleur/service de Resource Graph dédié.
19. Conserver un adaptateur de sortie vers `ResourceSuggestion` afin que le Query Builder existant continue de fonctionner pendant la migration.
20. Rebrancher explicitement `QueryController` pour les suggestions, `SemanticCatalogReader`/`OracleQueryTool` pour la validation, `OracleFieldDiscovery` et la synchronisation sur le contrat canonique ou ses adaptateurs. Le chemin d'exécution historique peut rester derrière son adaptateur, mais aucun nouveau consommateur ne doit lire directement un quatrième tableau de métadonnées.

#### Fichiers principalement concernés

- `app/Services/ResourceDefinitionRegistry.php` ;
- `app/Services/Workers/WorkersResourceRegistry.php` ;
- `app/Services/OracleResourceCatalog.php` ;
- `app/Services/SemanticCatalogReader.php` ;
- `app/Services/OracleDescribeNormalizer.php` ;
- `app/Services/OracleSchemaSynchronizationService.php` ;
- `app/Models/OracleResourceField.php` ;
- `app/Models/OracleResourceSchemaSnapshot.php` ;
- schéma et workflow de publication du catalogue sémantique ;
- `app/Providers/AppServiceProvider.php` ;
- nouveau contrôleur de Resource Graph ;
- tests de catalogues et fixtures Oracle.

#### Critère de sortie

Pour un contexte donné, une seule vue canonique et déterministe expose :

```text
Workers
└── WorkRelationships
    └── Assignments
        └── Managers
```

Chaque ressource et relation indique sa provenance. Le graphe porte une empreinte immuable reproductible et ses IDs canoniques sont reliés sans ambiguïté aux clés historiques. Une divergence critique ou l'absence de version sémantique publiée empêche l'authoring et l'exécution du Resource Graph, sans affecter le moteur historique.

---

### Phase 3 — Finaliser la persistance et la validation HTTP

**Durée indicative :** 2 à 3 jours

**But :** enregistrer et recharger un Query Graph sans casser les anciennes requêtes.

#### Travaux

1. Conserver la migration nullable `query_graph`, après revue de son application réelle.
2. Ajouter un schéma versionné au JSON.
3. Enregistrer l'empreinte du Resource Graph hybride utilisée lors de la sauvegarde et définir la réaction à une dérive : revalidation, avertissement ou refus explicite selon l'impact.
4. Ajouter une validation de forme minimale à `StoreQueryRequest`.
5. Borner la taille du payload JSON avant hydratation.
6. Hydrater et valider le graphe dans un service applicatif, pas dans une longue liste de règles Laravel récursives.
7. Résoudre le `CatalogContext` depuis une connexion accessible à l'utilisateur, puis revalider le graphe contre la liste blanche publiée courante. Un `tenantId`, une version ou une empreinte envoyés par le client ne sont jamais une autorisation.
8. Définir les règles de coexistence :

   ```text
   mode == agent                         → query_graph interdit, resource_path null
   mode != agent et query_graph null     → resource_path requis pour une requête simple
   mode != agent et query_graph non null → racine et relations validées contre le Resource Graph
   ```

9. Définir le contrat des paramètres runtime : chaque cible doit identifier un `nodeId` et une clé de filtre/paramètre autorisée. Tant que `RuntimeQueryParameterBinder` n'est pas adapté à ce contrat, refuser les paramètres runtime sur les graphes au lieu de les injecter dans les paramètres plats de la racine.
10. Mettre à jour création et modification.
11. Renvoyer le graphe à la page d'édition.
12. Copier le graphe lors du clonage d'une requête.
13. Étendre `SemanticLineageService` pour parcourir tous les nœuds, champs, filtres et relations.
14. Ajouter le graphe aux réponses API de lecture utiles, sans exposer de métadonnées sensibles.
15. Détecter une version de format inconnue et retourner une erreur explicite.
16. Exclure côté requête et côté validation les graphes des sources et cibles `QueryChain` pendant le POC.
17. Ne pas convertir automatiquement les anciennes requêtes pendant le POC.

#### Points d'entrée à couvrir

- `QueryController::store` ;
- `QueryController::update` ;
- `QueryController::edit` ;
- `QueryController::duplicate` ;
- `QueryApiController::show` ;
- `SemanticLineageService` ;
- sélection et validation de `QueryChainService` ;
- modèle `Query` ;
- tests de store/update/clone/compatibilité.

#### Critère de sortie

- un graphe valide est sauvegardé puis rouvert à l'identique ;
- un graphe invalide est rejeté côté serveur ;
- le graphe est revalidé dans le contexte tenant autorisé et sa dérive de catalogue est détectable ;
- une requête `mode=agent` ne peut pas contenir de `query_graph` ;
- une QueryChain ne peut pas sélectionner un graphe tant que l'extraction hiérarchique n'est pas définie ;
- une requête historique avec `query_graph = null` se crée, se modifie, se clone et s'exécute comme avant.

---

### Phase 4 — Implémenter « Add Child Query »

**Durée indicative :** 3 à 5 jours

**But :** livrer le premier jalon produit demandé, sans imposer encore l'exécution récursive complète.

#### Travaux frontend

1. Créer des types TypeScript récursifs :

   ```text
   QueryGraphDraft
   QueryNodeDraft
   QueryEdgeDraft
   ResourceDefinitionDto
   RelationDefinitionDto
   ```

2. Gérer l'état par `nodeId`, avec un reducer ou des helpers immuables testables.
3. Extraire de `QueryConfigPanel` les contrôles réutilisables :
   - sélection de champs ;
   - filtres ;
   - tri ;
   - paramètres ;
   - affichage des capacités.
4. Créer un `QueryNodeEditor` récursif.
5. Créer un bouton `AddChildQuery` qui :
   - consulte le Resource Graph ;
   - n'affiche que les relations CHILD autorisées ;
   - calcule la profondeur depuis le draft ;
   - désactive l'action à la profondeur maximale ;
   - indique clairement l'absence d'enfants.
6. Permettre la suppression d'une branche avec confirmation si elle contient des descendants.
7. Appliquer les capacités par nœud.
8. Sauvegarder et restaurer l'arbre.
9. Conserver l'éditeur historique pour les requêtes `query_graph = null`.
10. Ajouter les textes FR/EN/ES conformément aux conventions du projet.

#### Travaux backend associés

1. Le endpoint des enfants résout lui-même le tenant parmi les connexions accessibles à l'utilisateur et ne traite ni `tenantId`, ni version, ni `depth` envoyés par le client comme une preuve.
2. Pour une requête partagée, lecture, exécution et clonage reconstruisent le contexte avec les droits et la connexion du lecteur. L'édition reste réservée au propriétaire conformément à `QueryPolicy::update`.
3. La validation définitive de profondeur se fait sur le graphe complet à la sauvegarde.
4. L'API peut renvoyer `maxDepth` comme information d'interface.
5. Les ressources feuilles renvoient une liste vide stable.

#### Fichiers principalement concernés

- `resources/js/components/queries/query-builder.tsx` ;
- `resources/js/components/queries/query-config-panel.tsx` ;
- nouveaux composants `query-node-editor` et `add-child-query` ;
- `resources/js/lib/query-spec.ts` ou un nouveau `query-graph.ts` ;
- `resources/js/hooks/use-live-preview.ts` ;
- pages create/edit ;
- locales FR/EN/ES ;
- endpoint Resource Graph et ses tests.

#### Limite volontaire de cette phase

Tant que la phase d'exécution n'est pas livrée, l'authoring doit rester derrière son propre flag et l'exécution derrière un flag distinct désactivé. L'interface peut construire, sauvegarder et rouvrir le graphe, mais `run`, export et schedule doivent refuser explicitement ce graphe ; aucun aperçu ne doit laisser croire qu'une exécution récursive complète a déjà eu lieu.

#### Critère de sortie — premier jalon produit

L'utilisateur peut construire, sauvegarder et rouvrir :

```text
Workers
└── WorkRelationships
    └── Assignments
        └── Managers
```

Il peut aussi :

- sélectionner des champs par nœud ;
- supprimer une branche ;
- voir les feuilles ;
- être bloqué à la profondeur configurée ;
- utiliser une autre définition de ressources fictive sans changement du composant générique.

---

### Phase 5 — Introduire un Query Planner minimal

**Durée indicative :** 2 à 3 jours

**But :** séparer définitivement la demande utilisateur de sa stratégie d'exécution.

#### Travaux

1. Créer `ExecutionPlan` et `ExecutionStep`.
2. Faire valider le Query Graph avant planification.
3. Calculer les dépendances entre étapes.
4. Compiler chaque binding logique vers le `sourceNodeId` exact de la branche ou vers une clé sûre du `CatalogContext`.
5. Ajouter automatiquement les identifiants requis aux champs techniques du bon nœud source.
6. Calculer les templates de chemin sans les résoudre encore.
7. Compiler les filtres structurés et tris vers les paramètres Oracle avec une liste blanche d'opérateurs par type ; réutiliser un serializer commun et ne jamais concaténer directement une valeur utilisateur dans `q`.
8. Calculer pour chaque étape une borne effective à partir des capacités observées, de la pagination Oracle et des limites serveur.
9. Estimer le nombre maximal d'appels à partir de ces bornes ; refuser une ressource non bornable ou un plan qui dépasse les budgets configurés.
10. Produire uniquement une stratégie `RECURSIVE` dans le premier planner.
11. Préparer le type de stratégie sans implémenter `EXPAND` ou `BATCH`.
12. Adapter `RuntimeQueryParameterBinder` au ciblage explicite par `nodeId`, ou maintenir le refus explicite défini en phase 3.
13. Extraire de `OracleQueryTool`, sous forme réutilisable, les validations de champs, filtres, tris, limites, chemins autorisés et fallbacks sûrs dont le nouvel exécuteur aura besoin.
14. Définir un résolveur de chemin qui encode chaque valeur dynamique et un redactor qui remplace les segments d'identifiants avant tout log, audit ou message d'erreur.

#### Critère de sortie

Le graphe Workers jusqu'à Managers produit un plan déterministe et entièrement testable sans connexion Oracle. Tous les placeholders pointent vers une frame précise, les filtres sont compilés sans injection, aucune étape non bornable n'est acceptée, aucun paramètre runtime ne peut viser implicitement le mauvais nœud et aucun chemin contenant un identifiant métier ne peut être envoyé aux logs en clair.

---

### Phase 6 — Exécuter récursivement le Query Graph

**Durée indicative :** 5 à 8 jours

**But :** livrer le second jalon produit avec un résultat hiérarchique naturel.

#### Travaux

1. Interdire l'activation du flag d'exécution tant que les validations extraites de `OracleQueryTool`, l'encodage des placeholders et la redaction des chemins ne sont pas branchés et testés. Le nouvel adaptateur ne doit jamais appeler directement `FusionClient` avec une requête moins protégée que le chemin historique.
2. Introduire un port d'exécution générique, puis un adaptateur Oracle Fusion réutilisant `FusionManager` et `FusionClient` derrière cette couche de garde-fous.
3. Implémenter `RecursiveExecutionStrategy` uniquement.
4. Exécuter d'abord la racine.
5. Pour chaque ligne racine :
   - créer une frame de contexte ;
   - extraire les identifiants ;
   - résoudre le path de chaque enfant ;
   - exécuter l'enfant ;
   - recommencer récursivement.
6. Utiliser un contexte distinct par ligne et par branche.
7. Assembler les enfants sous une clé stable issue de la définition de ressource.
8. Retirer les identifiants techniques non demandés après assemblage.
9. Implémenter une pagination récursive bornée pour la racine et les collections enfants. Si un budget ou une limite interrompt une réponse avec `hasMore=true`, retourner une métadonnée `truncated` explicite avec le nœud et la raison ; ne jamais présenter silencieusement la collection comme complète.
10. Définir des garde-fous configurables :
   - limite racine ;
   - limite enfant par parent ;
   - nombre maximal de pages par nœud ;
   - nombre maximal d'appels ;
   - profondeur ;
   - durée maximale ;
   - volume maximal de résultat.
11. Commencer en séquentiel pour garantir la correction ; introduire la concurrence seulement après mesure.
12. Définir une politique d'erreur de graphe distincte de `OracleExecutionPolicy`, qui ne contrôle aujourd'hui que les fallbacks Oracle :
    - échec strict ;
    - ou résultat partiel explicite avec erreurs par nœud.
13. Enrichir l'observabilité avec `nodeId`, `resourceId`, `relationId`, nombre d'appels, lignes et durée par étape, uniquement avec des chemins redacted.
14. Revalider le graphe juste avant le planning avec le tenant Oracle auquel le lecteur courant a accès ; une empreinte historique ne contourne jamais la publication courante.
15. Créer un `SavedQueryExecutor` commun afin que le web, l'API, les exports et les schedules ne réimplémentent pas la détection agent/legacy/graphe.
16. Garder les chemins agent et Oracle historique intacts pour `query_graph = null`.

#### Résultat attendu

```json
{
  "workers": [
    {
      "PersonNumber": "10001",
      "workRelationships": [
        {
          "PeriodOfServiceId": 123,
          "assignments": [
            {
              "AssignmentNumber": "E10001",
              "managers": []
            }
          ]
        }
      ]
    }
  ]
}
```

#### Points d'entrée à unifier

- exécution web ;
- API personnelle ;
- export ;
- tâches planifiées ;
- validation qualité ;
- éventuellement templates lorsqu'ils accepteront un graphe.

L'aplatissement CSV/XLSX avancé reste hors scope. Tant qu'une politique d'aplatissement n'est pas définie, un export tabulaire de Query Graph doit être désactivé explicitement ou limité à un format JSON hiérarchique.

#### Critère de sortie — second jalon produit

- Workers → WorkRelationships → Assignments → Managers s'exécute avec des réponses Oracle simulées ;
- le résultat est correctement imbriqué ;
- une branche vide reste une liste vide ;
- les identifiants de trois ancêtres sont résolus correctement ;
- la pagination ne tronque jamais silencieusement une collection ;
- aucun identifiant dynamique n'apparaît en clair dans les logs ou erreurs ;
- les validations de champs, filtres, tris, limites et fallbacks du moteur historique sont conservées ou remplacées par un équivalent testé ;
- les budgets empêchent une explosion incontrôlée du nombre d'appels ;
- les requêtes historiques continuent d'utiliser l'exécuteur existant.

---

### Phase 7 — Durcir la découverte hybride

**Durée indicative :** 3 à 5 jours

**But :** réduire progressivement la part de métadonnées manuelles.

#### Travaux

1. Étudier les payloads `/describe` réellement reçus pour chaque niveau.
2. Étendre le normalizer aux informations disponibles :
   - types ;
   - nullabilité ;
   - queryability ;
   - tri ;
   - actions ;
   - enfants ;
   - capacités.
3. Ne pas inventer une relation ou un binding que le payload ne prouve pas.
4. Garder les paths, identifiants et bindings dans les overrides lorsqu'ils ne sont pas découvrables.
5. Mettre en cache par tenant, version Oracle et version du catalogue.
6. Recalculer l'empreinte définie en phase 2 à chaque publication et conserver la provenance détaillée de ses composants.
7. Comparer cette empreinte à celles enregistrées et calculer les requêtes hiérarchiques affectées par une dérive.
8. Exiger la publication du catalogue sémantique avant qu'une découverte élargisse la liste blanche.

#### Critère de sortie

Les métadonnées observées enrichissent le Resource Graph sans contourner la gouvernance et sans modifier silencieusement une requête enregistrée.

---

### Phase 8 — Optimiser après le POC Workers

**À démarrer uniquement après mesure de l'exécution récursive.**

Travaux possibles :

- `ExpandExecutionStrategy` ;
- `BatchExecutionStrategy` ;
- choix de stratégie par le planner ;
- déduplication des appels identiques ;
- concurrence bornée ;
- cache d'étapes ;
- optimisation de la pagination récursive (préchargement ou concurrence bornée) ;
- coût estimé dans l'interface ;
- prévention avancée du N+1 ;
- reprise asynchrone pour les gros graphes.

---

### Phase 9 — Ajouter d'autres APIs Oracle

Chaque nouvelle API doit suivre le même parcours :

1. collecter les métadonnées observées ;
2. définir les overrides techniques minimaux ;
3. publier les ressources et relations dans la gouvernance ;
4. ajouter des tests de contrat et fixtures ;
5. vérifier les budgets d'exécution ;
6. ne modifier ni le Query Builder générique, ni le Query Graph, ni le planner pour un nom de ressource particulier.

L'ordre possible après Workers sera décidé séparément. Suppliers, Invoices, Purchase Orders, Financials et les relations cross-module restent hors du POC.

---

## 9. Premier lot d'implémentation recommandé

Le prochain lot doit commencer par clôturer la phase 0, puis réunir les phases 1 et 2, sans toucher encore à l'interface.

### Objectif du lot

> Obtenir un Resource Graph Workers canonique et un Query Graph fiable, sérialisable et validé.

### Ordre exact

1. Écrire le test de round-trip Workers → WorkRelationships → Assignments → Managers.
2. Corriger la désérialisation.
3. Ajouter IDs uniques, validation source/cible, cycles, doublons et profondeur par branche.
4. Déplacer la policy de profondeur dans la configuration.
5. Unifier la représentation des identifiants.
6. Valider tous les placeholders et bindings.
7. Introduire `ResourceCatalog` et `ResourceDefinitionProvider`.
8. Découpler le registre central de Workers.
9. Transformer Workers en provider d'overrides.
10. Ajouter un adaptateur temporaire du catalogue historique.
11. Ajouter un merge déterministe et sa provenance.
12. Étendre la gouvernance sémantique aux relations imbriquées et publier leurs sources/cibles explicites.
13. Versionner les snapshots et produire l'empreinte du catalogue hybride.
14. Valider le Resource Graph obtenu avec les fixtures Oracle disponibles.
15. Mettre à jour l'endpoint des enfants pour utiliser ce catalogue dans un contexte tenant autorisé.
16. Rejouer les tests du moteur historique.

### Critères d'acceptation du lot

- round-trip sans perte ;
- profondeur falsifiée rejetée ;
- relation source/cible invalide rejetée ;
- cycle rejeté ;
- IDs de nœud uniques ;
- branche courte non bloquée par une autre branche à profondeur maximale ;
- placeholder sans binding rejeté ;
- registre générique sans dépendance directe vers Workers ;
- provenance disponible ;
- correspondance non ambiguë entre IDs historiques, sémantiques et canoniques ;
- relations profondes représentées et publiées dans la gouvernance sémantique ;
- empreinte hybride déterministe et snapshots immuables ;
- Workers → WorkRelationships → Assignments → Managers découvert par le contrat canonique ;
- aucun changement du comportement des requêtes historiques ;
- aucune condition sur les noms Workers dans le moteur générique.

---

## 10. Stratégie de tests

### 10.1 Tests de domaine

- sérialisation/désérialisation d'un arbre de quatre niveaux ;
- arbre avec deux branches de profondeurs différentes ;
- ID vide ou dupliqué ;
- relation source incorrecte ;
- relation cible incorrecte ;
- enfant avec mauvais parent ;
- profondeur falsifiée ;
- cycle structurel par réutilisation d'un `nodeId` ancêtre ;
- répétition légitime d'un même `resourceId` avec des `nodeId` distincts ;
- même `relationId` répété sous un parent rejeté, mais même `resourceId` autorisé dans deux branches ;
- incohérence entre les deux représentations d'identifiants pendant leur période de migration ;
- limites `max_depth`, `max_nodes` et taille JSON testées juste avant, à et juste après chaque borne ;
- ressource feuille ;
- placeholder manquant ;
- binding orphelin ;
- opérateur de filtre interdit et valeur incompatible avec le type du champ ;
- encodage de valeur de chemin ;
- même resourceId présent dans deux frames distinctes.

### 10.2 Tests de catalogue

- merge Oracle + sémantique + override ;
- provenance de chaque valeur ;
- override limité aux propriétés autorisées ;
- IDs de ressources dupliqués refusés ;
- IDs de relations dupliqués refusés ;
- ressource non publiée refusée ;
- absence de version sémantique publiée : authoring/exécution de graphe fermés, moteur historique inchangé ;
- relation dont la source est absente refusée ;
- relation vers une ressource absente refusée ;
- conflit critique refusé ;
- fallback historique seulement lorsque prévu ;
- variation par tenant et version Oracle ;
- mapping déterministe entre IDs historiques, sémantiques et canoniques ;
- relations imbriquées avec source et cible publiées, jamais une cible nulle implicite ;
- empreinte hybride stable pour les mêmes composants et différente dès qu'un composant versionné change ;
- snapshots immuables et isolés par tenant/version d'API ;
- observation d'un tenant incapable d'auto-publier une ressource globalement ;
- Workers complet à partir de fixtures.

### 10.3 Tests HTTP et persistance

- enfants de Workers ;
- enfants de WorkRelationships ;
- enfants d'Assignments ;
- feuille Managers ;
- profondeur UI informative mais validation serveur autoritaire ;
- à profondeur maximale, aucune proposition ne peut être ajoutée ;
- sauvegarde d'un graphe ;
- rechargement ;
- modification ;
- clonage ;
- version de schéma inconnue ;
- graphe invalide ;
- empreinte de catalogue obsolète avec dérive compatible et incompatible ;
- tenant ou version arbitraire envoyés par le client ignorés ou rejetés ;
- accès refusé lorsque l'utilisateur ou le lecteur d'une requête partagée n'a pas accès à la connexion Oracle visée ;
- requête `mode=agent` avec `query_graph` rejetée ;
- graphe absent des sélecteurs QueryChain et rejeté par la validation serveur comme source ou cible ;
- ancienne requête avec `query_graph = null` ;
- flags de lecture, authoring et exécution désactivés sur web, API, export et schedule ;
- un `query_graph` non nul ne tombe jamais silencieusement sur l'exécuteur legacy ;
- migration `up` sur base vide et sur base contenant des requêtes historiques ;
- anciennes lignes conservées avec `query_graph = null` et cast JSON vérifié après rechargement ;
- rollback de migration testé hors production ;
- migration JSON testée sur le même moteur que la production, pas uniquement SQLite.

### 10.4 Tests frontend

- ajout de WorkRelationships ;
- ajout d'Assignments ;
- ajout de Managers ;
- suppression d'une branche ;
- limite de profondeur ;
- feuille sans bouton actif ;
- champs et filtres indépendants par nœud ;
- sauvegarde et restauration du draft ;
- édition historique inchangée ;
- erreurs 404, 422 et 429 de l'API correctement présentées.

Le projet ne possède pas actuellement de runner de tests composants frontend. Deux options :

1. ajouter Vitest et React Testing Library dans un lot technique court ;
2. couvrir d'abord l'intégration via tests HTTP/Inertia, TypeScript, ESLint, build et scénario manuel reproductible.

La première option est recommandée avant que l'éditeur récursif ne devienne complexe.

Chaque nouvelle version du format JSON doit avoir une stratégie explicite : upcaster testé depuis la version précédente, maintien en lecture seule, ou refus clair. Il ne doit jamais exister de conversion implicite approximative.

### 10.5 Tests d'exécution

- racine Workers seule ;
- Workers → WorkRelationships ;
- Workers → WorkRelationships → Assignments ;
- Workers → WorkRelationships → Assignments → Managers ;
- compilation de chaque placeholder vers le bon `sourceNodeId` lorsque le même `resourceId` apparaît plusieurs fois ;
- compilation sûre des filtres structurés vers `q` et rejet d'un opérateur non autorisé ;
- plan refusé pour une ressource enfant dont la collection n'a aucune borne vérifiable ;
- plusieurs workers et plusieurs enfants ;
- branche vide ;
- identifiant absent ;
- erreur Oracle à un niveau intermédiaire ;
- mode strict et mode partiel ;
- paramètre runtime ciblé par `nodeId` et cible ambiguë rejetée ;
- pagination racine et enfant avec `hasMore=true` ;
- arrêt borné portant une métadonnée `truncated`, jamais silencieux ;
- limite d'appels ;
- timeout global ;
- assemblage hiérarchique ;
- retrait des champs techniques ;
- conservation des validations de champs, filtres, tris, limites et fallbacks sûrs ;
- encodage des identifiants utilisés dans les chemins ;
- absence de chemin résolu et d'identifiant métier dans les logs et erreurs ;
- lignage de tous les nœuds ;
- exécution web, API et schedule via le même service et le tenant autorisé du lecteur.

### 10.6 Commandes de validation par lot

```bash
php artisan test tests/Unit/Domain
php artisan test tests/Feature/QueryChildResourcesTest.php
php artisan test tests/Feature/Queries/QueryStoreTest.php
php artisan test tests/Feature/Queries/QueryUpdateTest.php
php artisan test tests/Feature/Queries/QueryRunTest.php
php artisan test tests/Feature/Oracle/OracleQueryToolTest.php
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run types:check
npm run lint:check
npm run format:check
npm run build
composer ci:check
```

Avant fusion d'un jalon produit, exécuter également la suite PHP complète.

---

## 11. Migration et compatibilité

### 11.1 Matrice de comportement

| Type de requête | `query_graph` | Comportement |
|---|---:|---|
| Agent | obligatoirement `null` | Builder et exécuteur agent actuels ; `resource_path` reste nul |
| Oracle historique | `null` | Builder et exécuteur Oracle actuels |
| Nouvelle requête racine simple | optionnel pendant la transition | Garder le format historique tant que le mode graphe n'est pas choisi |
| Nouvelle requête hiérarchique | non null | Builder récursif puis nouvel exécuteur |
| Graphe enregistré, authoring actif, exécution désactivée | non null | Lecture/édition autorisées ; run, export et schedule refusés explicitement |
| Graphe enregistré et exécution activée | non null | Nouvel exécuteur de graphe, jamais de fallback silencieux vers le legacy |
| QueryChain avec une source ou cible graphe | non null | Refusée pendant le POC ; support futur via `SavedQueryExecutor` et extraction hiérarchique explicite |
| Version de graphe inconnue | non null | Refus explicite, aucune interprétation approximative |

### 11.2 Séquence technique de déploiement

1. Tester la migration sur une copie représentative utilisant le moteur de base de données de production.
2. Mesurer la durée et le niveau de verrouillage de l'`ALTER TABLE` avant la fenêtre de déploiement.
3. Déployer la colonne nullable seule.
4. Déployer le backend compatible avec tous les flags désactivés.
5. Vérifier création, lecture, édition, clonage et exécution des requêtes historiques.
6. Activer le Resource Graph en lecture pour des utilisateurs internes.
7. Déployer le frontend récursif sans activer l'authoring globalement.
8. Activer l'authoring Workers en canary, tout en gardant l'exécution de graphe désactivée.
9. Surveiller erreurs de validation, versions inconnues, taille des graphes et dérives de catalogue.
10. Déployer le planner/exécuteur dans un lot ultérieur.
11. Activer l'exécution en canary seulement après les tests Oracle simulés et réels.

Chaque étape doit pouvoir être annulée par son flag sans exécuter le `down()` en production.

### 11.3 Pas de backfill initial

Ne pas convertir automatiquement tous les `expand` existants en Query Graph. Un `expand` historique peut contenir un chemin que le Resource Graph canonique ne sait pas encore expliquer. La conversion automatique risquerait de changer la sémantique.

Un outil de conversion pourra être ajouté plus tard pour les requêtes compatibles, avec aperçu du diff et rollback.

### 11.4 Double écriture

Éviter deux sources concurrentes pour une même requête. Si une double écriture temporaire est nécessaire pour une racine simple, elle doit être produite par un seul serializer et vérifiée par un test d'équivalence.

### 11.5 Rollback

Le rollback fonctionnel recommandé est :

1. désactiver d'abord `FUSION_QUERY_GRAPH_EXECUTION_ENABLED`, puis `FUSION_QUERY_GRAPH_AUTHORING_ENABLED` ou `FUSION_RESOURCE_GRAPH_ENABLED` si nécessaire ;
2. conserver la colonne nullable et les données graphes ;
3. continuer d'exécuter toutes les requêtes historiques ;
4. ne pas supprimer les graphes enregistrés ;
5. réactiver après correction.

Supprimer la colonne en production ne doit pas être le premier mécanisme de rollback.

---

## 12. Impacts transversaux à ne pas oublier

### 12.1 Exécutions dispersées

Le nouveau graphe devra être pris en compte dans :

- `QueryController` ;
- `QueryApiController` ;
- `QueryExportRunner` ;
- `RunScheduledQuery` ;
- validation qualité ;
- templates si le format y est autorisé ;
- lignage et audit.

La création d'un `SavedQueryExecutor` commun évitera cinq implémentations divergentes.

### 12.2 Observabilité

Une exécution hiérarchique doit pouvoir indiquer :

- graphe et version de catalogue utilisés ;
- stratégie choisie ;
- nombre d'appels Oracle ;
- durée totale et par étape ;
- lignes racines et enfants ;
- nœud fautif ;
- erreur sûre, sans URL contenant de donnée sensible ;
- budget prévu et budget consommé.

### 12.3 Lignage

Le lignage doit parcourir récursivement :

- toutes les ressources ;
- toutes les relations ;
- tous les champs sélectionnés ;
- tous les champs de filtre et de tri ;
- tous les identifiants techniques utilisés ;
- la version du catalogue.

### 12.4 Sécurité

- les paths sont toujours issus du Resource Graph gouverné ;
- le client ne fournit jamais une URL complète à exécuter ;
- le contexte tenant est reconstruit côté serveur depuis une connexion accessible au lecteur ;
- tous les placeholders sont encodés ;
- aucun placeholder non résolu n'atteint `FusionClient` ;
- `FusionClient` et ses appelants ne journalisent jamais un path résolu contenant un identifiant Worker, relation ou assignment ; ils utilisent le template redacted, les IDs techniques du graphe et un identifiant de corrélation ;
- les validations de champs, filtres, tris, limites et fallbacks sûrs de `OracleQueryTool` restent appliquées par une couche commune ;
- les limites d'appels sont appliquées côté serveur ;
- l'exécution partagée utilise toujours la connexion du lecteur ;
- les fixtures ne contiennent aucune donnée personnelle réelle.

### 12.5 Internationalisation

Les IDs, noms techniques et paths restent stables et non traduits. Les labels et descriptions proviennent du catalogue sémantique et suivent FR/EN/ES.

---

## 13. Risques principaux et réponses proposées

| Risque | Réponse |
|---|---|
| `/describe` ne fournit pas les bindings profonds | Système hybride avec overrides techniques versionnés |
| Trois catalogues divergent | Contrat canonique et merge déterministe avec provenance |
| Explosion N+1 | Budgets dans le planner, limites par nœud, métriques, puis stratégies expand/batch |
| Même ressource répétée dans une branche | Contexte par frame/nodeId, pas par resourceId global |
| Changement de schéma Oracle | Empreinte, version de catalogue, détection d'impact et revalidation |
| Une observation tenant élargit la liste blanche globale | Publication sémantique obligatoire ; les faits observés restent isolés par tenant et version |
| Version API codée en dur à de nombreux endroits | Introduire progressivement un contexte/version canonique, sans refonte globale initiale |
| Graphes invalides envoyés par le frontend | Hydrator et validator côté serveur |
| Identifiants métier présents dans les paths de logs | Redaction obligatoire avant l'activation de l'exécuteur de graphe |
| Collection enfant tronquée silencieusement | Pagination bornée et métadonnée `truncated` explicite à toute limite atteinte |
| Nouvel exécuteur contourne les garde-fous historiques | Extraction et tests de contrat de la couche commune avant tout appel bas niveau |
| Résultat imbriqué incompatible avec CSV/XLSX | JSON hiérarchique d'abord, aplatissement reporté |
| Exécution différente entre web, API et jobs | `SavedQueryExecutor` commun |
| Prototype mélangé à d'autres changements du worktree | Périmètre phase 0 tracé dans le bilan ; figer ensuite cet état dans le découpage Git choisi par le propriétaire |
| Régression future de la baseline CI | Utiliser la référence phase 0 de 649 tests et 4 001 assertions ; aucun échec ne peut devenir une exemption globale |
| Moteur de production absent des manifests du dépôt | SQLite reste la référence observable ; la CI teste aussi MySQL 8.4 et PostgreSQL 16, puis devra être alignée sur le déploiement réel |
| Fixtures Oracle non confirmées sur un tenant | Conserver `tenant-capture-pending` et ne prendre aucune décision de métadonnées de phase 2 avant une capture autorisée et anonymisée |
| Chunk frontend principal supérieur à 500 kB | Mesurer et introduire du code splitting lors du lot d'optimisation |

---

## 14. Hors scope jusqu'à validation du POC

Ne pas développer pendant les premiers jalons :

- Suppliers ;
- Invoices ;
- Purchase Orders ;
- Receipts ;
- Financials ;
- Procurement ;
- relations cross-module ;
- traduction en langage naturel ;
- génération de requêtes par IA ;
- `CrossResourceExecutionStrategy` ;
- aplatissement tabulaire avancé ;
- optimisation batch complète ;
- concurrence non bornée ;
- migration automatique de toutes les requêtes historiques.

---

## 15. Définition de terminé du POC Workers

Le POC est terminé uniquement lorsque :

- le Resource Graph Workers provient du catalogue canonique hybride ;
- ses métadonnées critiques sont validées avec des fixtures Oracle ;
- l'utilisateur peut construire Workers → WorkRelationships → Assignments → Managers ;
- le graphe peut être sauvegardé, rouvert et cloné sans perte ;
- la profondeur maximale est une policy serveur ;
- le moteur n'a aucune condition spéciale sur les noms Workers ;
- le planner produit un plan déterministe ;
- la stratégie récursive résout les trois identifiants ancêtres ;
- le résultat est hiérarchique ;
- toute pagination interrompue est signalée explicitement ;
- aucun identifiant métier n'est exposé dans les logs ou erreurs ;
- les garde-fous du moteur historique s'appliquent également au graphe ;
- les limites d'appels et de volume sont appliquées ;
- le lignage et l'observabilité couvrent chaque nœud ;
- les textes FR/EN/ES sont complets ;
- les requêtes historiques restent fonctionnelles ;
- les suites PHP, TypeScript, lint, format et build sont vertes ;
- les feature flags séparés permettent un rollback fonctionnel immédiat de la lecture, de l'authoring ou de l'exécution.

---

## 16. Séquence de livraison synthétique

| Ordre | Lot | Résultat | Durée indicative | Statut |
|---:|---|---|---:|---|
| 0 | Baseline et fixtures | Point de départ reproductible | 0,5–2 j* | **✅ Terminée le 30 juillet 2026** |
| 1 | Domaine robuste | Graphe fiable et sérialisable | 1–2 j | À faire |
| 2 | Catalogue hybride et gouvernance profonde | Resource Graph Workers canonique et versionné | 3–5 j | À faire |
| 3 | Persistance | Sauvegarde, édition, clonage et lignage | 2–3 j | À faire |
| 4 | Add Child Query | Arbre constructible et réouvrable | 3–5 j | À faire |
| 5 | Planner minimal | Plan récursif déterministe | 2–3 j | À faire |
| 6 | Exécution récursive | Résultat Workers hiérarchique | 5–8 j | À faire |
| 7 | Discovery renforcée | Moins d'overrides manuels | 3–5 j | À faire |
| 8 | Optimisations | Expand, batch et performance | Après mesure | À faire |
| 9 | Autres APIs | Extension sans modifier le moteur | Après POC | À faire |

**Estimation jusqu'au premier jalon UI :** environ 9,5 à 17 jours.

**Estimation jusqu'à l'exécution récursive du POC :** environ 16,5 à 28 jours.

\* Le statut réel enregistré pour la phase 0 remplace son estimation initiale ; il inclut la correction des sept problèmes globaux et de la dette qualité révélée par la validation complète.

Ces estimations sont des ordres de grandeur, pas un engagement. La principale inconnue fonctionnelle est la disponibilité et la qualité des métadonnées Oracle réelles pour les ressources enfants.

---

## 17. Prochaine action concrète

La phase 0 est close. Commencer maintenant par la phase 1 :

> Stabiliser le Query Graph existant et introduire le contrat de catalogue canonique, sans modifier encore le Query Builder.

Le lot est terminé lorsque le graphe Workers à quatre niveaux passe un round-trip complet, que toutes ses relations sont validées et que le registre générique n'a plus de dépendance directe à Workers.
