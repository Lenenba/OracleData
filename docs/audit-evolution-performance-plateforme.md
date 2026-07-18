# Audit d'évolution et de performance de la plateforme OracleData

## 1. Objectif

Ce document formalise les améliorations recommandées pour faire évoluer OracleData vers une bibliothèque de requêtes plus rapide, mieux organisée et plus collaborative. La décision prioritaire est désormais de rendre les environnements Oracle et leurs connexions d'authentification personnels à chaque utilisateur.

## Décision d'architecture prioritaire — tenants et connexions par utilisateur

Cette décision remplace toute recommandation antérieure réservant la création ou la gestion des tenants au `super_admin`, ainsi que toute résolution globale des identifiants Oracle depuis `config/fusion.php` ou les variables `FUSION_<CLE>_*`.

### Décision fonctionnelle

- chaque utilisateur authentifié possède et gère ses propres environnements Oracle depuis « Paramètres > Connexions Oracle » ;
- un environnement, appelé `OracleTenant`, décrit une cible Oracle : libellé, clé locale, URL de base, état actif et caractère par défaut ;
- une `AuthConnection` décrit comment son propriétaire s'authentifie sur cet environnement ;
- un tenant peut recevoir plusieurs connexions d'authentification, même si la première livraison n'expose qu'une connexion Basic principale ;
- l'inscription n'est achevée qu'après la création et la vérification serveur d'une première connexion Basic active et définie par défaut ;
- après l'onboarding, l'utilisateur peut ajouter, tester, modifier, désactiver et supprimer ses connexions, sous réserve de conserver au moins une connexion active ;
- le `super_admin` gouverne la plateforme, les politiques et l'audit, mais ne devient ni propriétaire des tenants utilisateurs ni lecteur de leurs secrets.

### Schéma relationnel cible

```text
users
- id
- onboarding_completed_at nullable
- autres attributs du profil

oracle_tenants
- id
- user_id FK -> users.id, non nullable à l'issue de la migration
- key
- label
- base_url
- is_default
- is_active
- timestamps

Contraintes :
- unique (user_id, key)
- index (user_id, is_active, is_default)
- un seul tenant actif par défaut par utilisateur, préservé transactionnellement par le service métier

auth_connections
- id
- oracle_tenant_id FK -> oracle_tenants.id, suppression en cascade
- user_id FK -> users.id, champ de cloisonnement dénormalisé si conservé
- name
- auth_type: basic aujourd'hui, types supplémentaires ultérieurement
- identifier
- secret chiffré
- configuration JSON chiffrée nullable
- is_default
- is_active
- verified_at nullable
- last_tested_at nullable
- last_test_succeeded_at nullable
- timestamps

Contraintes :
- unique (oracle_tenant_id, name)
- index (user_id, is_active)
- auth_connections.user_id = oracle_tenants.user_id si le champ dénormalisé est conservé
- une seule connexion active par défaut par tenant

queries
- oracle_tenant_id FK nullable -> oracle_tenants.id
- la FK représente uniquement la cible préférée du propriétaire, jamais un droit transmis à un lecteur
```

La chaîne d'autorité est `User -> OracleTenant -> AuthConnection`. Les identifiants, secrets et options d'authentification ne doivent plus être portés directement par `oracle_tenants`. Le champ `tenant_key` peut rester transitoirement pour la compatibilité de migration, mais les nouvelles relations utilisent les identifiants internes et un périmètre utilisateur explicite.

### Invariants d'isolation

- toute lecture ou mutation d'un tenant est filtrée par `user_id = utilisateur courant` et vérifiée par une Policy ;
- un identifiant de tenant ou de connexion soumis par le navigateur n'est jamais accepté sans vérification de propriété ;
- les clés de tenant ne sont uniques que dans le périmètre d'un utilisateur ; deux utilisateurs peuvent employer la même clé ;
- les caches et la mémoïsation sont indexés par utilisateur et invalidés après chaque mutation ;
- les secrets sont chiffrés au repos, exclus des payloads Inertia, des logs, des exceptions et des événements d'audit ;
- les jobs et commandes reçoivent un `user_id` d'exécution explicite et recréent un résolveur de connexions dans ce périmètre ;
- aucune solution de repli vers un tenant global ou les identifiants d'un autre utilisateur n'est autorisée ;
- la désactivation ou la suppression de la dernière connexion active est refusée après l'onboarding.

### Règle d'exécution des requêtes personnelles et partagées

La définition d'une requête est partageable ; les accès Oracle ne le sont pas.

1. Pour sa propre requête, l'auteur peut enregistrer un `oracle_tenant_id` lui appartenant comme cible préférée.
2. Pour une requête partagée, le lecteur choisit une connexion active qui lui appartient ; son tenant par défaut peut être présélectionné dans l'interface.
3. Le `oracle_tenant_id` du propriétaire de la requête n'est jamais utilisé pour le lecteur et ne lui donne aucun accès à l'URL ou aux secrets du propriétaire.
4. À terme, une préférence personnelle par requête pourra mémoriser le tenant choisi par le lecteur, à condition qu'elle référence l'un de ses propres tenants.
5. Le backend autorise d'abord l'accès à la définition de requête, puis résout séparément la connexion du lecteur et vérifie qu'elle est active et vérifiée.
6. Le futur journal d'exécution devra enregistrer l'utilisateur exécutant, le tenant et la connexion réellement utilisés, et non la cible préférée de l'auteur.
7. Si aucune connexion personnelle compatible n'est disponible, l'exécution est bloquée avec une invitation à en configurer une ; aucun fallback implicite n'est tenté.

Le clonage d'une requête partagée l'associe au tenant par défaut du nouveau propriétaire ; celui-ci peut ensuite choisir une autre connexion qui lui appartient.

### Responsabilités des contrôleurs et services

- `OnboardingController` affiche le formulaire initial et délègue à un service transactionnel le test puis la création du tenant et de sa connexion Basic ;
- `OracleTenantController` liste uniquement les tenants de l'utilisateur courant et gère création, test, modification, activation, choix par défaut et suppression après autorisation ;
- `QueryController` ne doit jamais convertir la cible d'une requête partagée en accès au tenant de son auteur ; il exige ou résout la connexion personnelle de l'exécutant ;
- `FusionManager` ou son successeur est toujours lié à un utilisateur (`forUser(...)` pour les jobs) et ne consulte plus les credentials globaux ;
- `TenantConnectionService` centralise transactions, chiffrement, test de connectivité, unicité des valeurs par défaut et protection de la dernière connexion active ;
- les contrôleurs restent minces : aucune résolution de secret ou règle d'isolation ne doit être dupliquée dans React ou dans les actions HTTP.

### Parcours d'onboarding détaillé

1. L'utilisateur crée son compte ou confirme son adresse selon le parcours d'inscription retenu.
2. Tant que `onboarding_completed_at` est vide, un middleware l'oriente vers la page « Configurer ma première connexion Oracle » ; les pages métier et le dashboard restent inaccessibles.
3. Le formulaire demande un nom d'environnement, une clé locale, l'URL Oracle, l'identifiant Basic et le mot de passe. La première version fixe `auth_type = basic`.
4. Le frontend peut proposer « Tester la connexion », mais la validation décisive est toujours rejouée côté serveur avec une URL autorisée, des timeouts courts et sans exposer le détail technique de l'échec.
5. Si le test échoue, rien n'est persisté et l'utilisateur reste sur l'étape avec un message actionnable.
6. Si le test réussit, une transaction crée le `OracleTenant` actif et par défaut, crée l'`AuthConnection` Basic active, vérifiée et par défaut, puis renseigne `onboarding_completed_at`.
7. Le cache des tenants de cet utilisateur est invalidé, puis l'utilisateur est redirigé vers le dashboard avec la nouvelle connexion présélectionnée.
8. Une reprise est idempotente : un utilisateur ayant terminé l'onboarding est redirigé vers le dashboard et le service verrouille sa ligne pendant la transaction afin qu'un double envoi ne crée pas deux connexions.
9. Depuis ses paramètres, l'utilisateur peut ensuite ajouter plusieurs tenants et, à terme, plusieurs `AuthConnection` par tenant ; il peut changer la valeur par défaut sans perdre ses requêtes.

`onboarding_completed_at` sert de marqueur de parcours, mais l'accès effectif à Oracle reste conditionné à l'existence d'au moins une connexion active et vérifiée. Les services de mutation préservent cet invariant après l'onboarding.

### Migration des tenants globaux et de la configuration

La migration doit être progressive et contrôlée :

1. ajouter `users.onboarding_completed_at`, `oracle_tenants.user_id`, `auth_connections` et `queries.oracle_tenant_id` ; seul `oracle_tenants.user_id` est temporairement nullable pendant son backfill ;
2. inventorier les tenants de base et ceux déclarés dans `config/fusion.php` ou `FUSION_<CLE>_*`, sans écrire leurs secrets dans les logs ;
3. affecter chaque tenant historique à un propriétaire explicite ; la migration accepte le seul utilisateur d'une installation mono-utilisateur ou le premier super-administrateur, et s'arrête avant toute mutation si l'affectation reste ambiguë ;
4. créer pour chaque tenant migré une connexion `basic` principale et chiffrée, sans dupliquer ses secrets pour tous les utilisateurs ;
5. rattacher les requêtes historiques par le couple `(queries.user_id, tenant_key)` et laisser les cas ambigus à `null` avec un rapport de migration ;
6. retirer `username` et `password` de `oracle_tenants`, puis rendre `oracle_tenants.user_id` non nullable lorsque tous les cas sont résolus ;
7. supprimer tout fallback d'exécution vers `FUSION_DEFAULT_TENANT` et `FUSION_<CLE>_{BASE_URL,USERNAME,PASSWORD}` ; les entrées de credentials peuvent subsister temporairement pour le seeding ou l'import legacy local, mais ne sont jamais résolues au runtime et doivent être retirées ou tournées après migration ;
8. invalider tous les caches de résolution, tester l'isolation avec au moins deux utilisateurs et faire tourner les credentials ayant transité historiquement en clair dans l'environnement ;
9. prévoir un rollback qui refuse de recréer un espace global si des clés identiques existent chez plusieurs utilisateurs.

Les tenants historiques ne doivent jamais être attribués à tous les comptes par commodité. Une connexion sans propriétaire explicite reste inactive jusqu'à résolution.

### Préparation au SSO sans l'implémenter maintenant

La connexion globale à OracleData et le SSO Oracle sont reportés. Le modèle prépare néanmoins cette évolution avec `auth_connections.auth_type` et `configuration` chiffrée. La première livraison accepte uniquement `basic` ; les futures stratégies (`oauth2`, `oidc_delegated`, compte de service ou mode hybride) seront ajoutées derrière une interface de résolution commune, sans modifier la relation de propriété.

Le futur SSO de la plateforme utilisera des tables d'identité distinctes et ne remplacera pas automatiquement l'autorisation vers Oracle. Aucun jeton SSO, fournisseur d'identité ni flux « on behalf of » n'est requis pour valider la présente étape.

### Suivi d'avancement

Dernière mise à jour : 17 juillet 2026.

| Étape | Sujet | État |
| ---: | --- | --- |
| 1 | Stabilisation et sécurité immédiate | **Fait — 17 juillet 2026** |
| 2 | Fondation multilingue FR/EN/ES | **En cours — 17 juillet 2026** |
| 3 | Tenants personnels, onboarding et contrôle d'accès | **En cours — fondation livrée le 17 juillet 2026** |
| 4 | Bibliothèque organisée | **En cours — 17 juillet 2026** |
| 5 | Partage ciblé et collaboration | À faire |
| 6 | Gouvernance et templates officiels | À faire |
| 7 | Couche sémantique Oracle | À faire |
| 8 | Fiabilité et tests de données | À faire |
| 9 | Exécution asynchrone et automatisation | À faire |
| 10 | Dashboards et analyse avancée | À faire |
| 11 | Copilote IA gouverné | À faire |
| 12 | Extensibilité et écosystème | À faire |
| 13 | SSO plateforme et Oracle | À faire — dernière étape |

Progression de l'étape 1 :

- [x] protéger l'accès aux tenants pendant la phase de stabilisation ;
- [x] mémoïser les tenants et invalider après mutation ;
- [x] ajouter pagination, recherche serveur et index ;
- [x] configurer les timeouts et erreurs Oracle ;
- [x] ajouter les métriques de base et identifiants de corrélation ;
- [x] valider les tests, l'analyse statique et le build de production.

Résultat de l'étape 1 :

- rôle minimal `super_admin` non attribuable depuis l'interface ;
- commande sécurisée `php artisan user:grant-super-admin {email}` ;
- gestion des tenants réorientée vers leur propriétaire : les utilisateurs standards gèrent uniquement leurs propres connexions ;
- entrée « Connexions Oracle » disponible dans les paramètres utilisateur après l'onboarding ;
- résolution des tenants mémoïsée par utilisateur et invalidée après mutation ;
- bibliothèque paginée à 25 éléments avec recherche serveur différée ;
- index composites sur propriétaire/visibilité et date de mise à jour ;
- colonne « Visibilité » retirée de la page exclusivement partagée ;
- timeouts Oracle configurables, retries bornés et messages sans fuite technique ;
- identifiant de corrélation et mesure `Server-Timing` sur chaque réponse ;
- journalisation structurée des appels Oracle et requêtes HTTP lentes ;
- migrations de stabilisation locales appliquées et `test@example.com` promu super-administrateur ;
- migration multi-tenant prête, avec arrêt sécurisé si les tenants historiques n'ont pas de propriétaire non ambigu ;
- 227 tests et 1028 assertions validés ;
- PHPStan, ESLint, TypeScript et build de production validés.

Chantier parallèle restant : **Étape 2 — finaliser la fondation multilingue FR/EN/ES**.

Progression de l'étape 2 :

- [x] ajouter la locale et le fuseau horaire au profil utilisateur ;
- [x] résoudre la locale côté Laravel avec fallback sécurisé (préférence, cookie, navigateur, puis français) ;
- [x] créer les catalogues backend et frontend FR/EN/ES ;
- [x] ajouter le fournisseur React et le sélecteur de langue ;
- [x] traduire navigation, authentification, bibliothèque et erreurs prioritaires ;
- [ ] ajouter glossaire et workflow XLIFF (reportés tant qu'aucune équipe de traduction externe n'intervient) ; le test de parité des clés FR/EN/ES est en place ;
- [x] valider les tests, l'analyse statique et le build de production.

Progression de l'étape 3 :

- [x] créer la relation `User -> OracleTenant -> AuthConnection` et chiffrer les secrets ;
- [x] livrer l'onboarding obligatoire avec test serveur et création atomique de la première connexion ;
- [x] ouvrir la gestion des connexions personnelles à tous les utilisateurs ;
- [x] isoler CRUD, résolution de clients et requêtes partagées dans le périmètre du lecteur ;
- [x] supprimer le fallback d'exécution vers les credentials globaux ;
- [x] couvrir onboarding, IDOR, connexions actives et requêtes partagées par des tests ;
- [ ] appliquer la migration sur chaque environnement après sauvegarde et vérification du propriétaire legacy ;
- [x] ajouter le journal d'audit des mutations et exécutions sans secrets — livré le 17 juillet 2026 : table immuable `audit_events`, service `AuditRecorder` refusant les clés sensibles, événements `tenant.created/updated/deleted`, `onboarding.completed`, `query.executed` et `admin.super_admin_granted`.

Progression de l'étape 4 :

- [x] créer `query_executions` et les agrégats atomiques sur `queries` — livré le 17 juillet 2026 : statuts `succeeded/failed` (asynchrone réservé à l'étape 9), tenant et connexion de l'exécutant réel, historique conservé après suppression de la requête, previews exclus ;
- [ ] créer catégories et tags traduisibles ;
- [ ] créer favoris et épinglage ;
- [ ] ajouter filtres, tris et statistiques à la bibliothèque ;
- [ ] créer les vues enregistrées ;
- [ ] optimiser le dashboard.

Fonctionnalités couvertes :

- tags et catégories ;
- favoris et épinglage ;
- historique des versions ;
- statistiques d'usage ;
- demandes de modification ;
- templates officiels verrouillés et clonables ;
- onboarding avec première connexion Basic active, vérifiée et par défaut ;
- gestion de plusieurs tenants et connexions par utilisateur ;
- partage ciblé avec des utilisateurs ou des groupes ;
- super-administration et gouvernance globale ;
- interface et contenus officiels multilingues en français, anglais et espagnol ;
- trajectoire SSO pour la plateforme et les tenants Oracle ;
- performance du backend, du frontend et des appels Oracle.

## 2. État actuel

### Architecture

- Laravel 13 et PHP 8.4 ;
- Inertia.js 3 ;
- React 19 et TypeScript ;
- Vite 8 et Tailwind CSS 4 ;
- Pest 4 ;
- SQLite dans l'environnement local ;
- cache, sessions et queue utilisant actuellement la base de données.

La plateforme possède de bonnes fondations : les autorisations sont centralisées dans une Policy, les appels Oracle passent par `FusionClient`, les routes coûteuses sont limitées et l'aperçu dynamique utilise déjà un debounce avec annulation des appels obsolètes.

Au moment de l'audit :

- la compilation de production réussit ;
- 199 tests réussissent ;
- 826 assertions sont validées.

## 3. Principaux risques de performance

### 3.1 Bibliothèque non paginée

La bibliothèque charge actuellement toutes les requêtes accessibles avec `get()`. Avec plusieurs centaines ou milliers de requêtes, cela augmentera le temps SQL, la mémoire Laravel, le payload Inertia et le nombre de lignes rendues par React.

Recommandations :

- pagination serveur de 25 ou 50 éléments ;
- recherche et filtres côté serveur ;
- sélection des seules colonnes nécessaires ;
- conservation des filtres dans l'URL ;
- debounce de la recherche.

### 3.2 Lectures répétées des tenants

`FusionManager` peut relire la table des tenants chaque fois qu'un libellé est demandé. Dans une liste, cela peut produire un N+1 et, si le cache n'est pas correctement segmenté, une fuite de métadonnées entre utilisateurs.

Recommandation : mémoïser la liste des tenants du seul utilisateur courant pendant toute la requête HTTP, inclure son `user_id` dans toute clé de cache persistante et invalider ce cache après création, modification ou suppression d'un tenant.

### 3.3 Index insuffisants

Index recommandés :

```text
queries(user_id, updated_at)
queries(visibility, updated_at)
queries(category_id, updated_at)
query_tag(tag_id, query_id)
query_executions(query_id, finished_at)
query_executions(user_id, finished_at)
query_user_preferences(user_id, is_pinned, pinned_at)
```

L'ordre exact devra être confirmé avec les requêtes finales et les plans d'exécution de la base de production.

### 3.4 Grands tableaux de résultats

Le tri, le filtrage, le rendu et l'export CSV sont actuellement réalisés dans le navigateur.

Recommandations :

- traitement local pour les petits résultats ;
- pagination ou virtualisation au-delà d'un seuil ;
- export CSV côté serveur pour les gros volumes ;
- chargement des sous-tableaux Oracle uniquement à leur ouverture ;
- limite maximale de lignes renvoyées au navigateur.

### 3.5 Dashboard

Certaines statistiques sont calculées en chargeant des collections complètes en PHP.

Recommandations :

- agrégations simples en SQL ;
- colonnes dédiées pour les classifications fréquemment filtrées ;
- agrégats pré-calculés pour les exécutions ;
- cache court pour les indicateurs peu changeants.

### 3.6 Appels Oracle

Les appels Oracle doivent recevoir :

- un timeout de connexion court ;
- un timeout global explicite ;
- un retry limité aux erreurs transitoires ;
- une journalisation de la durée et du statut ;
- des erreurs normalisées sans secret ni détail technique sensible ;
- un identifiant de corrélation pour le support.

### 3.7 Analyses agent synchrones

Une analyse agent peut réaliser plusieurs cycles LLM et plusieurs appels Oracle dans la même requête HTTP.

À terme, elle devrait être placée dans une queue avec :

- états `queued`, `running`, `completed`, `failed` et `cancelled` ;
- polling léger ou flux serveur ;
- progression visible ;
- délai maximal et limite de coût ;
- annulation lorsque possible.

### 3.8 Bundle frontend

Mesures observées lors du build :

- bundle principal : environ 160 Ko, soit 45,6 Ko gzip ;
- chunk Wayfinder : environ 319 Ko, soit 100,5 Ko gzip ;
- CSS : environ 103 Ko, soit 16,8 Ko gzip.

Le chunk Wayfinder doit être analysé : vérifier le tree-shaking, limiter les routes générées si possible et confirmer les dépendances réellement chargées au premier affichage. Les composants lourds du query builder peuvent aussi être chargés à la demande.

## 4. Tags et catégories

### Modèle recommandé

```text
categories
- id
- name
- slug unique
- color
- timestamps

tags
- id
- name
- slug unique
- timestamps

query_tag
- query_id
- tag_id

queries
- category_id nullable
```

Une requête possède une catégorie principale et plusieurs tags. Les tags ne doivent pas être stockés dans un JSON, car cela compliquerait les recherches, les contraintes et les statistiques.

### Interface

- catégorie et tags visibles dans la bibliothèque ;
- filtres par catégorie et tag ;
- recherche sur nom, description, propriétaire et tags ;
- filtres partageables grâce à l'URL ;
- administration des catégories selon les permissions.

## 5. Favoris et épinglage

Le favori appartient à l'utilisateur, pas à la requête.

```text
query_user_preferences
- user_id
- query_id
- is_favorite
- is_pinned
- pinned_at
- timestamps

Contrainte unique : (user_id, query_id)
```

Comportement attendu :

- favoris différents pour chaque utilisateur ;
- requêtes épinglées affichées en priorité ;
- action optimiste dans React ;
- rollback et notification en cas d'échec ;
- suppression automatique des préférences si la requête disparaît.

## 6. Historique des versions

### Modèle recommandé

```text
query_versions
- id
- query_id
- version_number
- created_by
- name
- description
- resource_path
- oracle_tenant_id nullable, cible préférée du propriétaire uniquement
- mode
- parameters JSON
- change_summary
- created_at

Contrainte unique : (query_id, version_number)
```

### Règles

- créer une version pour chaque changement fonctionnel ;
- ne pas versionner les favoris, épinglages ou exécutions ;
- décider explicitement si la visibilité doit être versionnée ;
- restaurer une ancienne version en créant une nouvelle version courante ;
- ne jamais effacer l'historique lors d'une restauration ;
- enregistrer l'auteur et un résumé ;
- ajouter un verrouillage optimiste via `lock_version` ou `updated_at`.

### Interface

- onglet « Historique » ;
- liste chronologique ;
- comparaison entre deux versions ;
- auteur, date et résumé ;
- restauration selon les autorisations.

## 7. Statistiques d'usage

### Journal d'exécution

```text
query_executions
- id
- query_id nullable si l'historique doit survivre à la suppression
- user_id, utilisateur ayant réellement exécuté la requête
- oracle_tenant_id, tenant appartenant à cet utilisateur
- auth_connection_id, connexion réellement utilisée
- status
- duration_ms
- rows_count
- started_at
- finished_at
- error_code
- timestamps
```

Statuts :

```text
queued
running
succeeded
failed
cancelled
```

### Agrégats rapides sur `queries`

```text
execution_count
successful_execution_count
last_executed_at
last_successful_execution_at
```

Les compteurs doivent être mis à jour atomiquement. Pour une requête partagée, `oracle_tenant_id` et `auth_connection_id` proviennent du lecteur qui lance l'exécution, jamais de la connexion enregistrée par l'auteur.

### Indicateurs

- nombre de tentatives ;
- nombre de succès ;
- taux de succès ;
- dernière tentative ;
- dernier succès ;
- durée moyenne et P95 ;
- nombre moyen de lignes ;
- ressources et tenants les plus sollicités.

Ne pas conserver les résultats Oracle complets par défaut. Stocker un code d'erreur normalisé plutôt qu'un message susceptible de contenir des données sensibles. Définir une durée de rétention.

## 8. Demandes de modification

### Modèle recommandé

```text
query_change_requests
- id
- query_id
- requested_by
- message
- status
- owner_response
- resolved_by
- resolved_at
- timestamps
```

États : `pending`, `accepted`, `rejected`, `completed`.

### Parcours

1. Un utilisateur clique sur « Demander une modification ».
2. Il décrit le changement souhaité.
3. Le propriétaire reçoit une notification.
4. Il accepte, refuse ou demande une précision.
5. Une fois le changement appliqué, la demande passe à `completed`.
6. La nouvelle version peut référencer la demande d'origine.

Les e-mails et intégrations externes doivent être envoyés via la queue.

## 9. Templates officiels verrouillés

### Modèle recommandé

Ajouter à `queries` :

```text
type: user | official
is_locked
published_at
published_by
```

Si un workflow éditorial est nécessaire, remplacer le simple verrouillage par des statuts `draft`, `review`, `published` et `archived`.

### Règles

- un template officiel publié est partagé ;
- un utilisateur standard ne peut ni le modifier, ni le supprimer, ni changer sa visibilité ;
- tous les utilisateurs autorisés peuvent le cloner ;
- le clone devient une requête utilisateur privée et modifiable ;
- toute modification officielle crée une version ;
- seuls les administrateurs autorisés peuvent publier ou déverrouiller.

La Policy Laravel reste la source d'autorité. Masquer un bouton dans React ne suffit pas.

### Interface

- badge « Template officiel » ;
- icône de verrouillage ;
- version publiée ;
- bouton « Utiliser ce template » ou « Cloner » ;
- filtre ou section dédiée.

## 10. Architecture cible

```text
users
  ├── oracle_tenants
  │     └── auth_connections
  ├── queries
  ├── query_user_preferences
  ├── query_executions
  └── query_change_requests

oracle_tenants
  ├── user propriétaire
  ├── auth_connections
  └── queries du propriétaire qui le prennent comme cible préférée

queries
  ├── category
  ├── tags via query_tag
  ├── query_versions
  ├── query_executions
  ├── query_change_requests
  └── query_user_preferences
```

## 11. Infrastructure de production

### Base de données

SQLite convient au développement et aux tests. Pour une plateforme collaborative en production, utiliser PostgreSQL ou MySQL pour mieux gérer la concurrence, les volumes, les index, les sauvegardes et la réplication.

### Redis

Utiliser Redis pour :

- cache ;
- sessions ;
- rate limiting ;
- queues ;
- verrous distribués ;
- progression temporaire des jobs.

### Queue

Placer dans la queue :

- analyses agent longues ;
- notifications e-mail ;
- exports volumineux ;
- certains calculs d'agrégats ;
- opérations de maintenance.

## 12. Observabilité

Mesures minimales :

- temps P50, P95 et P99 des pages Laravel ;
- nombre de requêtes SQL par page ;
- requêtes SQL lentes ;
- durée et taux d'erreur Oracle par tenant et ressource ;
- durée d'attente et d'exécution des jobs ;
- volume des payloads Inertia ;
- taille du bundle JavaScript ;
- erreurs frontend ;
- exécutions par requête et utilisateur.

Objectifs initiaux proposés :

```text
Bibliothèque hors appel Oracle : P95 serveur < 250 ms
Retour visuel après lancement d'une analyse : < 150 ms
Recherche bibliothèque : P95 < 300 ms
Nombre de requêtes SQL : constant par page paginée
Erreur brute ou page blanche : 0
```

Ces valeurs devront être adaptées à l'infrastructure réelle.

## 13. Sécurité et fiabilité

- appliquer toutes les autorisations côté serveur ;
- vérifier la propriété du tenant et de la connexion à chaque résolution ;
- segmenter caches, jobs et métriques par utilisateur exécutant ;
- introduire des permissions pour les templates officiels ;
- ne jamais exposer les secrets Oracle ;
- normaliser les erreurs ;
- utiliser des identifiants de corrélation ;
- limiter durée et coût des agents ;
- définir la rétention des exécutions et notifications ;
- tester les accès entre propriétaire, utilisateur et administrateur ;
- envisager la suppression logique si l'historique doit être conservé.

## 14. Feuille de route

### Phase 1 — Fondations de performance

1. Mémoïser les tenants par utilisateur.
2. Paginer la bibliothèque côté serveur.
3. Déplacer recherche et filtres côté serveur.
4. Ajouter les index principaux.
5. Limiter les colonnes Eloquent sélectionnées.
6. Ajouter timeouts et erreurs Oracle normalisées.
7. Mettre en place les premières métriques.

### Phase 2 — Usage et organisation

1. Créer `query_executions` et les agrégats.
2. Créer catégories et tags.
3. Créer favoris et épinglage.
4. Ajouter filtres, tris et statistiques à la bibliothèque.
5. Optimiser le dashboard.

### Phase 3 — Gouvernance

1. Créer les templates officiels.
2. Ajouter les rôles et permissions.
3. Verrouiller les templates dans la Policy.
4. Ajouter leur clonage et leur version publiée.
5. Créer l'historique et la restauration des versions.

### Phase 4 — Collaboration

1. Créer les demandes de modification.
2. Ajouter les notifications internes.
3. Envoyer les notifications externes via la queue.
4. Lier les demandes acceptées aux versions produites.

### Phase 5 — Exécution avancée

1. Passer les analyses agent dans la queue.
2. Afficher la progression et permettre l'annulation.
3. Déplacer les gros exports côté serveur.
4. Virtualiser les grands tableaux.
5. Auditer et réduire le bundle frontend.

## 15. Tests à prévoir

### Backend

- pagination et filtrage des requêtes accessibles ;
- absence de N+1 sur les tenants ;
- création d'un tenant limitée à son utilisateur propriétaire ;
- refus des accès croisés par identifiant deviné ;
- onboarding impossible sans connexion Basic testée, active, vérifiée et par défaut ;
- refus de désactiver ou supprimer la dernière connexion active ;
- exécution d'une requête partagée avec la connexion du lecteur uniquement ;
- jobs résolus dans le périmètre explicite de leur utilisateur ;
- favoris propres à chaque utilisateur ;
- ordre des épingles ;
- création et restauration des versions ;
- compteurs atomiques d'exécution ;
- conservation des échecs et annulations ;
- sécurité des demandes de modification ;
- verrouillage des templates officiels ;
- clonage en requête privée ;
- permissions administratives ;
- timeouts et erreurs Oracle.

### Frontend

- recherche avec debounce ;
- conservation des filtres dans l'URL ;
- rollback des actions optimistes ;
- affichage des badges et statistiques ;
- états vides, chargement et erreur ;
- redirection vers l'onboarding tant que la première connexion n'est pas validée ;
- ajout et gestion de plusieurs connexions depuis les paramètres ;
- sélecteur des seuls tenants du lecteur sur une requête partagée ;
- navigation dans l'historique ;
- progression d'une exécution asynchrone.

### Performance

- nombre constant de requêtes SQL ;
- bibliothèque contenant plusieurs milliers de requêtes ;
- résultats Oracle volumineux ;
- mesure du payload Inertia ;
- analyse du bundle de production ;
- exécutions simultanées.

## 16. Critères de réussite

La plateforme aura franchi un niveau supérieur lorsque :

- la bibliothèque restera rapide avec plusieurs milliers de requêtes ;
- les requêtes seront retrouvées facilement avec recherche, tags et catégories ;
- favoris et épinglages seront personnels ;
- les modifications seront traçables et restaurables ;
- les statistiques distingueront tentatives, succès et échecs ;
- les requêtes partagées pourront recevoir des demandes de modification ;
- chaque utilisateur pourra configurer plusieurs tenants sans intervention d'un administrateur ;
- aucun utilisateur ne pourra voir ou utiliser le tenant ou les secrets d'un autre ;
- le dashboard sera inaccessible avant la vérification de la première connexion Basic ;
- une requête partagée s'exécutera avec une connexion appartenant à son lecteur ;
- les templates officiels seront verrouillés, versionnés et clonables ;
- les analyses longues ne bloqueront plus les requêtes HTTP ;
- les performances et erreurs seront mesurées ;
- les tests fonctionnels, de sécurité et de performance resteront verts.

## 17. Synthèse de l'audit initial

Les fonctionnalités proposées sont cohérentes avec la direction de la plateforme. Elles doivent être construites autour de tables dédiées pour les préférences utilisateur, les versions, les exécutions et les demandes de modification.

La priorité est la pagination, les index, la mémoïsation des tenants et l'observabilité. Ces fondations permettront ensuite d'ajouter les fonctions collaboratives sans détériorer la fluidité de l'application.

## 18. Visibilité : décision produit et UX

### La visibilité reste nécessaire dans le système

L'existence d'un menu « Requêtes partagées » ne supprime pas le besoin d'un état d'accès. Le backend doit toujours déterminer qui peut consulter, exécuter, cloner ou administrer une requête. En revanche, afficher une colonne « Visibilité » sur toutes les pages est souvent redondant.

Décision recommandée :

- conserver l'information d'accès dans le modèle et les Policies ;
- retirer la colonne de la page dédiée « Requêtes partagées » ;
- afficher un badge compact dans les vues mixtes et « Mes requêtes » ;
- afficher le détail des destinataires sur la page de détail ;
- déplacer le changement d'accès dans une action explicite « Gérer le partage » ;
- éviter qu'un simple clic sur un badge change accidentellement les droits.

### Remplacer le binaire `private/shared`

Le champ actuel ne permet pas le partage ciblé. Le modèle cible devrait utiliser :

```text
access_level
- private       propriétaire uniquement
- restricted    utilisateurs ou groupes explicitement autorisés
- organization  tous les utilisateurs autorisés de la plateforme
```

Les templates officiels utilisent en complément leur statut de publication et leurs permissions administratives.

### Affichage selon la page

| Page | Affichage recommandé |
| --- | --- |
| Mes requêtes | Badge Privée, Restreinte ou Organisation et action « Gérer le partage » |
| Requêtes partagées | Pas de colonne de visibilité ; propriétaire et origine du partage |
| Toutes les requêtes | Badge compact pour comprendre l'origine de l'accès |
| Détail | Résumé complet des droits, destinataires et actions autorisées |
| Administration | Niveau d'accès, propriétaire, destinataires et historique |

## 19. Partage ciblé et envoi à des utilisateurs

### Objectif

Permettre de partager une requête avec une personne, plusieurs personnes, une équipe ou toute l'organisation, sans la rendre automatiquement accessible à tous.

### Modèle recommandé

```text
query_shares
- id
- query_id
- shared_by
- recipient_type: user | group
- recipient_id
- permission: view | execute | clone | manage
- status: pending | accepted | declined | revoked
- expires_at nullable
- accepted_at nullable
- timestamps

Contrainte unique :
(query_id, recipient_type, recipient_id)
```

Pour bénéficier de clés étrangères strictes, deux tables séparées `query_user_shares` et `query_group_shares` constituent une alternative plus robuste à la relation polymorphique.

### Groupes et équipes

```text
groups
- id
- name
- description
- owner_id
- timestamps

group_user
- group_id
- user_id
- role: owner | manager | member
```

Un groupe permet de gérer les droits d'une équipe une seule fois. Le retrait d'un utilisateur doit retirer immédiatement ses accès hérités.

### Permissions recommandées

- `view` : consulter les informations ;
- `execute` : exécuter la définition avec l'une des connexions actives appartenant au lecteur ;
- `clone` : créer une copie personnelle ;
- `manage` : gérer le partage, pour le propriétaire ou un délégataire explicite.

La modification directe de la source ne devrait pas être accordée par défaut. « Demander une modification » protège mieux la gouvernance et l'historique.

### Parcours « Envoyer à »

1. Le propriétaire choisit « Partager » ou « Envoyer à ».
2. Il recherche des utilisateurs ou groupes dans un sélecteur serveur paginé.
3. Il choisit les permissions et éventuellement une date d'expiration.
4. Les destinataires reçoivent une notification et voient la requête dans « Partagées avec moi ».
5. Une invitation peut exiger une acceptation avant activation.
6. Le propriétaire peut révoquer l'accès à tout moment.

### Sécurité et performance

- appliquer les droits dans la Policy, jamais uniquement dans React ;
- indexer `(recipient_type, recipient_id, status)` et `(query_id, status)` ;
- ne pas charger toute la liste des utilisateurs dans le navigateur ;
- journaliser partage, acceptation, changement de permission et révocation ;
- interdire qu'un destinataire repartage sans permission `manage` ;
- autoriser séparément l'accès à la définition puis la connexion choisie par le lecteur ;
- ne jamais transmettre, réutiliser ou révéler le tenant et les credentials du propriétaire lors du partage.

## 20. Administrateur général et limites de son périmètre

### Rôle cible

Créer un rôle `super_admin` capable d'administrer :

- utilisateurs, groupes et statuts de comptes ;
- rôles et permissions ;
- requêtes, partages et templates officiels ;
- politiques globales de connexion, domaines autorisés, quotas et incidents, sans lire les secrets utilisateurs ;
- catégories et tags administrés ;
- demandes de modification ;
- exécutions, files d'attente et incidents ;
- rétention, quotas et paramètres globaux ;
- journaux d'audit et indicateurs de santé.

### Modèle d'autorisation

Éviter un simple booléen `is_admin` si plusieurs responsabilités sont prévues.

```text
roles
- id
- name
- scope: global | group

permissions
- id
- name

role_user
- role_id
- user_id
- scope_type nullable
- scope_id nullable

permission_role
- permission_id
- role_id
```

Rôles initiaux possibles :

- `super_admin` ;
- `platform_admin` ;
- `template_publisher` ;
- `auditor` ;
- `group_manager` ;
- `user`.

Les permissions sont évaluées côté serveur. Un `Gate::before` peut simplifier le super-administrateur, mais toutes les actions sensibles doivent rester auditées.

### Console d'administration

- tableau de santé général ;
- gestion et suspension des utilisateurs ;
- attribution des rôles ;
- inventaire technique minimal des tenants (propriétaire, état, domaine et dernier test), sans identifiant ni secret ;
- politiques réseau et capacité de suspendre une connexion compromise avec justification auditée ;
- approbation et publication des templates ;
- consultation et annulation des jobs ;
- statistiques d'adoption et de performance ;
- journal d'audit filtrable et exportable.

### Garde-fous obligatoires

- MFA ou passkey obligatoire ;
- aucune auto-promotion depuis l'interface ;
- création initiale par commande sécurisée ou procédure contrôlée ;
- confirmation renforcée des opérations destructives ;
- journal immuable des actions administratives ;
- justification des accès exceptionnels aux données utilisateur ;
- aucun affichage, export ou usage des credentials d'un utilisateur ;
- aucune réattribution silencieuse d'un tenant personnel ;
- session administrative plus courte ;
- compte d'urgence séparé et surveillé ;
- impersonation uniquement si nécessaire, visible et auditée.

Le « contrôle total » signifie une capacité opérationnelle complète, pas un accès silencieux et non traçable aux données métier.

### Règle de propriété

Les routes de gestion des tenants ne sont pas des routes de super-administration : elles sont accessibles aux utilisateurs authentifiés ayant terminé l'onboarding, puis protégées par la propriété de chaque ressource. Le `super_admin` peut appliquer une suspension de sécurité ou consulter des métadonnées d'exploitation auditées, mais il ne configure pas à la place de l'utilisateur une connexion personnelle et ne peut jamais récupérer son secret.

## 21. Authentification actuelle et trajectoire SSO différée

### Périmètre de la présente évolution

La présente évolution implémente uniquement le stockage et la résolution de connexions Oracle personnelles. Le type obligatoire pour l'onboarding est `basic`. La connexion à OracleData continue d'utiliser le mécanisme d'authentification de plateforme existant.

```text
OracleTenant appartenant à un User
  └── AuthConnection
        ├── auth_type = basic
        ├── identifier
        ├── secret chiffré
        ├── configuration chiffrée nullable
        ├── is_active / is_default
        └── verified_at / last_tested_at
```

`auth_type` appartient à la connexion, pas au tenant : un même environnement pourra plus tard proposer plusieurs stratégies. Le code de résolution doit donc dépendre d'un contrat commun de fournisseur d'authentification et refuser un type inconnu, même si seule l'implémentation Basic existe aujourd'hui.

### Deux évolutions futures distinctes

Il faudra toujours séparer :

1. le SSO de connexion à OracleData, qui authentifie l'utilisateur sur la plateforme ;
2. l'authentification déléguée auprès d'Oracle, qui détermine sous quelle identité un tenant est consulté.

Une session SSO OracleData ne constitue jamais, à elle seule, un droit d'accès à Oracle Fusion. La propriété `User -> OracleTenant -> AuthConnection` reste la frontière d'autorisation, sauf si un futur modèle explicite de délégation est conçu et audité.

### Préparation de modèle, sans flux SSO maintenant

Les futurs types pourront compléter `auth_connections` sans déplacer les credentials dans `oracle_tenants` :

```text
auth_type
- basic
- oauth2_client_credentials, futur
- oidc_delegated, futur
- service_account, futur si nécessaire

configuration chiffrée
- références de coffre, scopes, audience et endpoints selon la stratégie
- aucun secret ou jeton persistant dans le navigateur
```

Les tables ci-dessous ne seront créées qu'à l'étape SSO de la feuille de route :

```text
identity_providers
- id
- name
- protocol: oidc | saml
- issuer
- client_id
- encrypted_client_secret ou référence de coffre
- discovery_url
- is_active

user_identities
- user_id
- identity_provider_id
- external_subject
- last_login_at
- claims JSON limitées
```

Le précédent modèle `user_tenant_access` n'est pas nécessaire pour les connexions personnelles : l'accès découle directement de `oracle_tenants.user_id`. Si des tenants d'équipe ou des délégations sont introduits plus tard, ils devront utiliser une table de délégation séparée, explicite, révocable et sans partage des secrets du propriétaire.

Avant toute prise en charge OAuth Oracle, réaliser une preuve de concept sur un environnement réel pour valider les flux supportés, les scopes, la durée et la révocation des jetons. Cette preuve de concept ne bloque ni l'onboarding Basic ni la gestion multi-connexions actuelle.

## 22. Capacités complémentaires pour devenir plus compétitif

### Gouvernance et confiance

- workflow brouillon, revue, certification, publication et archivage ;
- badge « Certifiée » distinct du template officiel ;
- propriétaire métier et responsable technique ;
- date de révision et alertes de contenu obsolète ;
- audit complet avec état avant/après ;
- lignée entre source, clones et versions dérivées.

### Collaboration

- espaces de travail et collections ;
- commentaires et mentions ;
- abonnements aux changements ;
- vues enregistrées et filtres partageables ;
- centre de notifications ;
- transfert de propriété au départ d'un utilisateur.

### Automatisation

- exécutions planifiées ;
- alertes sur seuil ou changement de résultat ;
- exports asynchrones avec expiration ;
- webhooks et API ;
- paramètres réutilisables avec formulaires validés ;
- comparaison de deux exécutions.

### Sécurité des données

- droits par tenant, domaine, ressource et environnement ;
- masquage des colonnes sensibles ;
- classification des données ;
- règles interdisant certains exports ;
- quotas de lignes, fréquence et coût ;
- approbation supplémentaire pour la production ;
- séparation claire test/production.

### Qualité et exploitation

- score de santé d'une requête ;
- détection des requêtes lentes, inutilisées ou en échec répété ;
- validation après changement de schéma Oracle ;
- circuit breaker par tenant en incident ;
- cache contrôlé des métadonnées non sensibles ;
- objectifs de service et tableaux opérationnels ;
- accessibilité, navigation clavier et localisation complète.

Ces capacités forment un catalogue cible à prioriser selon la valeur métier, le risque et le coût d'exploitation. Elles ne doivent pas être livrées simultanément.

## 23. Architecture cible étendue

```text
users
  ├── roles et permissions
  ├── user identities
  ├── Oracle tenants
  │     └── auth connections
  ├── groups
  ├── query shares
  └── query preferences

queries
  ├── category et tags
  ├── versions
  ├── executions
  ├── change requests
  ├── user/group shares
  └── audit events

platform
  ├── identity providers
  ├── politiques réseau et stratégies d'authentification supportées
  ├── roles et permissions
  ├── jobs et notifications
  └── audit global
```

## 24. Feuille de route étendue

### Phase 3 — Tenants personnels, onboarding et gouvernance renforcée

1. Ajouter rôles, permissions et super-administrateur.
2. Migrer les tenants globaux vers des propriétaires explicites et des `auth_connections`.
3. Livrer l'onboarding avec première connexion Basic vérifiée.
4. Protéger chaque tenant par sa Policy de propriété et supprimer le fallback de configuration globale.
5. Créer la console d'administration et l'audit sans exposer les secrets.
6. Créer et verrouiller les templates officiels.
7. Ajouter historique, comparaison et restauration.

### Phase 4 — Collaboration ciblée

1. Remplacer `private/shared` par `private/restricted/organization`.
2. Créer groupes et partage ciblé.
3. Ajouter « Partagées avec moi » et « Envoyer à ».
4. Créer les demandes de modification.
5. Ajouter notifications et expirations.

### Phase 6 — Différenciation produit

1. Collections, commentaires et abonnements.
2. Certification et révision périodique.
3. Planification, alertes et exports asynchrones.
4. Masquage des données et politiques d'export.
5. API, webhooks, lignée et score de santé.

### Phase 7 — Identité et SSO, après les autres fondations

1. Déployer le SSO OIDC ou SAML de la plateforme.
2. Ajouter provisionnement et déprovisionnement.
3. Conserver la propriété des connexions par utilisateur et concevoir séparément toute délégation d'équipe nécessaire.
4. Réaliser une preuve de concept Oracle déléguée.
5. Activer OAuth pour les tenants compatibles.
6. Conserver un mode hybride audité pour les autres cas.

## 25. Tests supplémentaires

- partage direct et partage de groupe ;
- acceptation, révocation et expiration ;
- interdiction du repartage non autorisé ;
- onboarding bloqué jusqu'à la réussite du test Basic et création atomique des valeurs par défaut ;
- ajout, modification et suppression des seuls tenants du propriétaire ;
- refus de désactiver ou supprimer la dernière connexion active ;
- refus d'exécution avec un tenant ou une connexion appartenant à un autre utilisateur ;
- exécution d'une requête partagée avec la connexion sélectionnée par le lecteur ;
- absence de fallback vers `config/fusion.php` et les variables de credentials globales ;
- impossibilité d'auto-promotion en super-administrateur ;
- audit des actions administratives ;
- connexion SSO et liaison d'identité ;
- déprovisionnement et fin de session ;
- isolation des identités et jetons entre tenants ;
- fallback contrôlé du mode délégué vers le compte de service ;
- masquage des données et restrictions d'export.

## 26. Conclusion générale

Le menu « Requêtes partagées » permet de simplifier l'interface, mais il ne remplace pas le modèle d'autorisation. La colonne de visibilité peut disparaître de cette page dédiée, tandis qu'un niveau d'accès explicite doit rester visible dans les vues où plusieurs origines sont mélangées.

L'évolution structurante combine deux frontières : le partage d'une définition de requête entre utilisateurs ou groupes, et la propriété strictement personnelle des tenants et connexions qui servent à l'exécuter. Partager une requête ne partage jamais une connexion Oracle. Ce modèle doit être gouverné par des Policies de propriété, une super-administration sans accès aux secrets et un audit complet.

Le SSO sera livré dans un second temps : authentification centralisée sur OracleData, puis éventuellement identité Oracle déléguée pour les environnements compatibles. D'ici là, `auth_type` prépare l'extension tandis que l'onboarding exige une connexion Basic personnelle, active et vérifiée.

L'ordre stratégique recommandé est : performance et observabilité, internationalisation, rôles et administration, partage ciblé, gouvernance et versions, couche sémantique, automatisation, IA et extensibilité, puis SSO plateforme et identité Oracle déléguée en dernier.

## 27. Internationalisation FR, EN et ES

### Objectif

La plateforme doit proposer une expérience complète en :

- français (`fr`) ;
- anglais (`en`) ;
- espagnol (`es`).

L'internationalisation doit être installée tôt dans la feuille de route. L'ajouter après la multiplication des écrans, notifications et templates rendrait la migration beaucoup plus coûteuse.

### Trois niveaux de traduction

Il faut distinguer :

1. l'interface de la plateforme : menus, boutons, validations, erreurs et notifications ;
2. les contenus administrés : catégories, templates officiels, descriptions sémantiques et documentation ;
3. les données retournées par Oracle, qui ne doivent pas être traduites automatiquement sans règle métier explicite.

Les noms et valeurs provenant directement d'Oracle doivent rester fidèles à la source. Une traduction automatique pourrait modifier le sens d'un statut, d'un libellé légal ou d'une valeur financière.

### Choix de langue

Ordre de résolution recommandé :

1. préférence enregistrée dans le profil utilisateur ;
2. langue fournie par le fournisseur d'identité lorsque le SSO sera activé ;
3. préférence du navigateur ;
4. français comme langue par défaut initiale.

Ajouter à `users` :

```text
locale: fr | en | es
timezone
date_format nullable
number_format nullable
```

Le sélecteur de langue doit être disponible dans le menu utilisateur et sur les pages publiques. Le changement de langue ne doit pas déconnecter l'utilisateur ni perdre l'état du formulaire.

### Backend Laravel

- placer les messages Laravel dans des catalogues `fr`, `en` et `es` ;
- traduire validations, Policies, notifications, e-mails et erreurs métier ;
- partager la locale active avec Inertia ;
- exécuter les jobs dans la locale du destinataire ;
- éviter les messages techniques construits par concaténation ;
- prévoir les formes singulier/pluriel ;
- formater dates, nombres, monnaies et fuseaux selon la locale.

### Frontend React

- centraliser les clés de traduction par domaine fonctionnel ;
- séparer les catalogues communs, requêtes, administration, tenants et authentification ;
- charger uniquement les catalogues nécessaires à la page ;
- interdire les textes métier codés directement dans les composants ;
- fournir une fonction typée pour détecter les clés manquantes ;
- prévoir les variations de longueur des textes dans les composants ;
- conserver les termes Oracle officiels lorsqu'ils n'ont pas de traduction métier approuvée.

Arborescence indicative :

```text
resources/lang/fr/
resources/lang/en/
resources/lang/es/

resources/js/locales/fr/
resources/js/locales/en/
resources/js/locales/es/
```

### XLIFF/XLF

Le format XLIFF peut être utilisé pour exporter et réimporter les catalogues destinés à une équipe de traduction professionnelle.

Le workflow recommandé est :

```text
Clés sources dans le dépôt
→ export XLIFF
→ traduction ou révision
→ validation terminologique
→ import
→ tests automatiques
→ publication
```

Le fichier XLF ne doit pas devenir l'unique source difficilement exploitable par le code. Les catalogues exécutables doivent rester versionnés avec l'application.

### Contenus officiels traduisibles

Les contenus administrés nécessitent des tables de traduction plutôt qu'un simple texte unique :

```text
category_translations
- category_id
- locale
- name
- description nullable

tag_translations
- tag_id
- locale
- name

query_template_translations
- query_id
- locale
- name
- description
- usage_instructions nullable

semantic_resource_translations
- semantic_resource_id
- locale
- business_name
- description
- examples nullable
```

Contrainte unique recommandée pour chaque table : `(entité_id, locale)`.

Les requêtes personnelles ne doivent pas être traduites automatiquement. Leur propriétaire peut créer des variantes ou demander une traduction assistée clairement identifiée comme telle.

### Terminologie contrôlée

Créer un glossaire métier trilingue contenant :

- terme Oracle source ;
- libellé français approuvé ;
- libellé anglais approuvé ;
- libellé espagnol approuvé ;
- définition ;
- domaine ;
- termes interdits ou obsolètes.

Ce glossaire alimentera l'interface, la couche sémantique et les prompts du copilote afin d'éviter des traductions différentes pour le même concept.

### Recherche multilingue

- indexer les libellés officiels dans les trois langues ;
- permettre une recherche avec les synonymes du glossaire ;
- normaliser accents et casse ;
- ne pas dupliquer les requêtes uniquement pour la traduction ;
- retourner le libellé de la locale active avec fallback vers la langue source.

### Performance

- charger les catalogues frontend par langue et par domaine ;
- mettre en cache les traductions officielles ;
- ne pas transmettre les trois langues dans chaque payload Inertia ;
- invalider le cache lors de la publication d'une traduction ;
- précompiler les catalogues en production.

### Qualité et tests

- test détectant les clés manquantes dans une langue ;
- test détectant les clés inutilisées ;
- test de fallback ;
- tests de pluriels, dates, monnaies et nombres ;
- captures visuelles des trois langues sur les écrans principaux ;
- vérification des textes longs et du responsive ;
- test des e-mails et notifications dans la langue du destinataire ;
- revue humaine des termes RH, finance et achats.

### Accessibilité

- déclarer correctement l'attribut HTML `lang` ;
- mettre à jour `lang` au changement de locale ;
- traduire les textes alternatifs et libellés accessibles ;
- maintenir la navigation clavier ;
- ne pas communiquer un statut uniquement par une couleur.

## 28. Vision fonctionnelle avancée consolidée

Les capacités suivantes complètent la cible compétitive déjà décrite :

### Couche sémantique Oracle

- synchronisation des métadonnées Oracle via les endpoints `/describe` ;
- noms métier, synonymes et définitions trilingues ;
- relations et jointures autorisées ;
- classification des champs sensibles ;
- propriétaire métier et responsable technique ;
- détection des changements de schéma ;
- identification automatique des requêtes affectées.

### Copilote IA gouverné

- question métier vers requête ;
- suggestion d'un template existant avant création ;
- explication du résultat et des filtres ;
- proposition de visualisation ;
- détection d'anomalies et comparaison de périodes ;
- génération de tests ;
- niveau de confiance et sources interrogées ;
- respect obligatoire des droits utilisateur, tenant et colonne.

### Cycle de vie des requêtes

```text
Brouillon
→ En validation
→ Approuvée
→ Certifiée
→ Publiée
→ Obsolète
→ Archivée
```

Chaque promotion peut exiger tests automatiques, approbation, résumé de changement et validation sur un tenant non productif.

### Tests de données

- résultat non vide ;
- fourchette de nombre de lignes ;
- unicité d'une clé ;
- valeurs obligatoires ;
- liste de statuts autorisés ;
- seuil de durée ;
- comparaison avec une exécution précédente ;
- alerte et retrait temporaire de certification en cas d'échec.

### Requêtes paramétrables

Les templates peuvent exposer des paramètres validés : dates, unité opérationnelle, statut, fournisseur, devise et tenant. Les listes de valeurs peuvent provenir d'Oracle et être mises en cache prudemment.

### Dashboards composables

Une requête certifiée peut devenir un tableau, KPI, graphique, tendance ou alerte. Chaque widget conserve la version, les paramètres, le tenant, la fréquence et le statut de certification.

### Automatisation événementielle

- exécutions planifiées ;
- réaction à des événements métier Oracle ;
- alertes sur seuil ou changement ;
- notification vers e-mail, Teams, Slack ou webhook ;
- export sécurisé avec expiration ;
- vérification des droits du destinataire au moment de la diffusion.

### Moteur de recommandation

- templates utiles selon le profil et l'équipe ;
- doublons potentiels ;
- requêtes privées candidates à la certification ;
- requêtes inutilisées à archiver ;
- requêtes lentes à optimiser ;
- tags, propriétaires ou dates de révision manquants.

### Plateforme extensible

- API avec scopes et quotas ;
- webhooks signés ;
- connecteurs de notifications ;
- nouveaux types de visualisation ;
- intégration future d'autres sources ;
- portail de développeurs et clés rotatives ;
- journal d'audit de chaque intégration.

### Traçabilité de bout en bout

Chaque résultat doit pouvoir être relié à l'utilisateur, au tenant, à l'identité Oracle, à la version, aux paramètres, aux ressources appelées, à la durée, aux exports et aux partages.

## 29. Feuille de route maîtresse — réalisation étape par étape

Cette feuille de route remplace l'ordre indicatif des sections précédentes. Chaque étape doit être livrée, testée et mesurée avant le démarrage de la suivante. Le SSO est volontairement placé en dernier.

### Étape 1 — Stabilisation et sécurité immédiate

- protéger l'accès aux tenants avant leur décentralisation ;
- mémoïser les tenants par utilisateur ;
- ajouter pagination, recherche serveur et index ;
- configurer timeouts et erreurs Oracle ;
- ajouter métriques de base et identifiants de corrélation.

### Étape 2 — Fondation multilingue FR/EN/ES

- choisir et installer l'architecture i18n ;
- ajouter `locale` et `timezone` au profil ;
- extraire les textes codés en dur ;
- traduire navigation, authentification, bibliothèque et erreurs ;
- mettre en place glossaire, XLIFF et tests de catalogues.

### Étape 3 — Tenants personnels, onboarding et contrôle d'accès

- relation `User -> OracleTenant -> AuthConnection` et contraintes d'isolation ;
- migration contrôlée des tenants globaux, des credentials et des requêtes historiques ;
- onboarding obligatoire avec première connexion Basic active, vérifiée et par défaut ;
- paramètres permettant à chaque utilisateur d'ajouter et gérer plusieurs connexions ;
- résolution des connexions strictement liée à l'utilisateur courant, y compris dans les jobs ;
- exécution des requêtes partagées avec la connexion du lecteur ;
- suppression des fallbacks `FUSION_DEFAULT_TENANT` et `FUSION_<CLE>_*` contenant des credentials ;
- Policies de propriété, audit sans secrets et super-administrateur sécurisé.

### Étape 4 — Bibliothèque organisée

- catégories et tags traduisibles ;
- favoris et épinglage ;
- vues enregistrées ;
- statistiques d'usage ;
- dashboard optimisé.

### Étape 5 — Partage ciblé et collaboration

- niveaux `private`, `restricted`, `organization` ;
- partage avec utilisateurs et groupes ;
- permissions, expiration et révocation ;
- centre de notifications ;
- demandes de modification, commentaires et mentions.

### Étape 6 — Gouvernance et templates officiels

- historique des versions ;
- comparaison et restauration ;
- templates verrouillés ;
- workflow de validation et certification ;
- propriétaires métier et dates de révision.

### Étape 7 — Couche sémantique Oracle

- catalogue de métadonnées synchronisé ;
- relations, synonymes et glossaire trilingue ;
- classification des données ;
- détection de changements de schéma ;
- lignée entre requêtes et ressources.

### Étape 8 — Fiabilité et tests de données

- assertions configurables ;
- validation automatique avant publication ;
- surveillance des requêtes certifiées ;
- score de santé ;
- détection des requêtes lentes ou cassées.

### Étape 9 — Exécution asynchrone et automatisation

- queue pour les agents et exports ;
- progression et annulation ;
- planification ;
- alertes et événements ;
- diffusion contrôlée et webhooks.

### Étape 10 — Dashboards et analyse avancée

- widgets composables ;
- requêtes paramétrables ;
- comparaisons temporelles ;
- exports volumineux côté serveur ;
- virtualisation des résultats.

### Étape 11 — Copilote IA gouverné

- génération fondée sur la couche sémantique ;
- suggestions et explications ;
- génération de tests et visualisations ;
- confiance, provenance et limites ;
- quotas, coûts et validation humaine.

### Étape 12 — Extensibilité et écosystème

- API publique interne ;
- scopes, quotas et clés rotatives ;
- webhooks et connecteurs ;
- moteur de recommandation ;
- intégration future d'autres sources.

### Étape 13 — SSO, en dernière étape

- SSO OIDC ou SAML de la plateforme ;
- provisionnement et déprovisionnement ;
- ajout éventuel de délégations explicites pour les tenants d'équipe, sans modifier la propriété des connexions personnelles ;
- preuve de concept OAuth Oracle sur un tenant réel ;
- nouvelles stratégies `auth_type` et identité Oracle déléguée pour les tenants compatibles ;
- mode hybride audité pour les jobs et tenants non compatibles ;
- tests complets de révocation, isolation et accès d'urgence.

### Règle de passage entre les étapes

Une étape est terminée uniquement lorsque :

- les critères d'acceptation sont validés ;
- les tests fonctionnels et de sécurité sont verts ;
- les tests d'isolation entre au moins deux utilisateurs sont verts ;
- aucune connexion ou secret global obsolète n'est encore résolu par l'application ;
- les métriques de performance ne régressent pas ;
- la documentation utilisateur et administrateur est à jour ;
- les trois langues sont complètes pour les fonctions livrées ;
- un plan de rollback existe ;
- les décisions techniques importantes sont documentées.
