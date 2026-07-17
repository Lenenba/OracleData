# Audit d'évolution et de performance de la plateforme OracleData

## 1. Objectif

Ce document formalise les améliorations recommandées pour faire évoluer OracleData vers une bibliothèque de requêtes plus rapide, mieux organisée et plus collaborative.

### Suivi d'avancement

Dernière mise à jour : 17 juillet 2026.

| Étape | Sujet | État |
| ---: | --- | --- |
| 1 | Stabilisation et sécurité immédiate | **Fait — 17 juillet 2026** |
| 2 | Fondation multilingue FR/EN/ES | **En cours — 17 juillet 2026** |
| 3 | Administration et contrôle d'accès | À faire |
| 4 | Bibliothèque organisée | À faire |
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

- [x] protéger l'administration des tenants ;
- [x] mémoïser les tenants et invalider après mutation ;
- [x] ajouter pagination, recherche serveur et index ;
- [x] configurer les timeouts et erreurs Oracle ;
- [x] ajouter les métriques de base et identifiants de corrélation ;
- [x] valider les tests, l'analyse statique et le build de production.

Résultat de l'étape 1 :

- rôle minimal `super_admin` non attribuable depuis l'interface ;
- commande sécurisée `php artisan user:grant-super-admin {email}` ;
- routes de gestion des tenants interdites aux utilisateurs standards ;
- entrée « Tenants Oracle » masquée pour les non-administrateurs ;
- résolution des tenants mémoïsée et invalidée après mutation ;
- bibliothèque paginée à 25 éléments avec recherche serveur différée ;
- index composites sur propriétaire/visibilité et date de mise à jour ;
- colonne « Visibilité » retirée de la page exclusivement partagée ;
- timeouts Oracle configurables, retries bornés et messages sans fuite technique ;
- identifiant de corrélation et mesure `Server-Timing` sur chaque réponse ;
- journalisation structurée des appels Oracle et requêtes HTTP lentes ;
- migrations locales appliquées et `test@example.com` promu super-administrateur ;
- 208 tests et 874 assertions validés ;
- PHPStan, ESLint, TypeScript et build de production validés.

Prochaine étape : **Étape 2 — Fondation multilingue FR/EN/ES**.

Progression de l'étape 2 :

- [ ] ajouter la locale et le fuseau horaire au profil utilisateur ;
- [ ] résoudre la locale côté Laravel avec fallback sécurisé ;
- [ ] créer les catalogues backend et frontend FR/EN/ES ;
- [ ] ajouter le fournisseur React et le sélecteur de langue ;
- [ ] traduire navigation, authentification, bibliothèque et erreurs prioritaires ;
- [ ] ajouter glossaire, workflow XLIFF et tests de complétude ;
- [ ] valider les tests, l'analyse statique et le build de production.

Fonctionnalités couvertes :

- tags et catégories ;
- favoris et épinglage ;
- historique des versions ;
- statistiques d'usage ;
- demandes de modification ;
- templates officiels verrouillés et clonables ;
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

`FusionManager` peut relire la table des tenants chaque fois qu'un libellé est demandé. Dans une liste, cela peut produire un N+1.

Recommandation : mémoïser la liste des tenants pendant toute la requête HTTP et invalider ce cache après création, modification ou suppression d'un tenant.

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
- tenant_key
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
- user_id
- tenant_key
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

Les compteurs doivent être mis à jour atomiquement.

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
  ├── queries
  ├── query_user_preferences
  ├── query_executions
  └── query_change_requests

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

1. Mémoïser les tenants.
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
- `execute` : exécuter sur les tenants autorisés ;
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
- croiser l'accès à la requête avec l'accès au tenant ciblé.

## 20. Administrateur général

### Rôle cible

Créer un rôle `super_admin` capable d'administrer :

- utilisateurs, groupes et statuts de comptes ;
- rôles et permissions ;
- requêtes, partages et templates officiels ;
- tenants Oracle et méthodes d'authentification ;
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
- scope: global | tenant | group

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
- `tenant_admin` ;
- `template_publisher` ;
- `auditor` ;
- `group_manager` ;
- `user`.

Les permissions sont évaluées côté serveur. Un `Gate::before` peut simplifier le super-administrateur, mais toutes les actions sensibles doivent rester auditées.

### Console d'administration

- tableau de santé général ;
- gestion et suspension des utilisateurs ;
- attribution des rôles ;
- matrice utilisateurs-tenants ;
- gestion des tenants et tests de connexion ;
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
- session administrative plus courte ;
- compte d'urgence séparé et surveillé ;
- impersonation uniquement si nécessaire, visible et auditée.

Le « contrôle total » signifie une capacité opérationnelle complète, pas un accès silencieux et non traçable aux données métier.

### Risque actuel à corriger

Les routes de gestion des tenants sont actuellement placées sous la seule authentification générale. Elles doivent être protégées rapidement par des permissions administratives avant une ouverture large de la plateforme.

## 21. SSO plateforme et SSO Oracle par tenant

### Deux problèmes différents

Il faut séparer :

1. le SSO de connexion à OracleData, qui authentifie l'utilisateur sur la plateforme ;
2. l'authentification déléguée auprès d'un tenant Oracle, qui détermine sous quelle identité les données Oracle sont consultées.

Une connexion SSO à OracleData ne garantit pas automatiquement qu'Oracle Fusion acceptera le même jeton. Chaque tenant doit être évalué selon sa configuration d'identité.

### Étape A — SSO de la plateforme

Supporter OIDC en priorité, puis SAML si nécessaire :

- connexion via l'Identity Provider de l'organisation ;
- association par identifiant immuable de l'IdP, pas uniquement par e-mail ;
- MFA pilotée par l'IdP ;
- révocation et désactivation des comptes ;
- accès d'urgence contrôlé ;
- provisionnement et déprovisionnement SCIM à terme.

```text
identity_providers
- id
- name
- protocol: oidc | saml
- issuer
- client_id
- encrypted_client_secret
- discovery_url
- is_active

user_identities
- user_id
- identity_provider_id
- external_subject
- last_login_at
- claims JSON limitées
```

### Étape B — Autorisation par tenant

Même avec le SSO plateforme, l'utilisateur ne doit voir que les tenants autorisés.

```text
user_tenant_access
- user_id
- oracle_tenant_id
- role_id nullable
- can_execute
- granted_by
- expires_at nullable
- timestamps
```

Les sélecteurs, validations backend et statistiques doivent tous respecter cette matrice.

### Étape C — Identité déléguée vers Oracle

Pour les tenants compatibles, remplacer progressivement le compte de service partagé par un flux OAuth/OIDC délégué ou « on behalf of » supporté par l'environnement Oracle concerné.

Principes :

- jetons courts et chiffrés ;
- refresh tokens seulement si indispensables ;
- secrets dans un coffre en production ;
- scopes minimaux ;
- révocation et expiration contrôlées ;
- aucun jeton persistant dans le navigateur ;
- corrélation entre l'utilisateur OracleData et l'identité Oracle ;
- audit de chaque exécution sous identité déléguée.

Le compte de service peut rester disponible pour les jobs planifiés et les tenants non compatibles, mais son usage doit être explicite, restreint et audité.

### Configuration par tenant

```text
authentication_mode
- service_account
- delegated_oauth
- hybrid

identity_provider_id nullable
oauth_configuration chiffrée
```

Avant l'implémentation, réaliser une preuve de concept sur un tenant réel pour valider les flux supportés, les scopes, la durée des jetons et les contraintes Oracle.

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
  ├── user tenant access
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
  ├── Oracle tenants et stratégies d'authentification
  ├── roles et permissions
  ├── jobs et notifications
  └── audit global
```

## 24. Feuille de route étendue

### Phase 3 — Gouvernance renforcée

1. Ajouter rôles, permissions et super-administrateur.
2. Protéger l'administration des tenants.
3. Créer la console d'administration et l'audit.
4. Créer et verrouiller les templates officiels.
5. Ajouter historique, comparaison et restauration.

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
3. Mettre en place la matrice utilisateurs-tenants.
4. Réaliser une preuve de concept Oracle déléguée.
5. Activer OAuth pour les tenants compatibles.
6. Conserver un mode hybride audité pour les autres cas.

## 25. Tests supplémentaires

- partage direct et partage de groupe ;
- acceptation, révocation et expiration ;
- interdiction du repartage non autorisé ;
- refus d'exécution sans accès au tenant ;
- impossibilité d'auto-promotion en super-administrateur ;
- audit des actions administratives ;
- connexion SSO et liaison d'identité ;
- déprovisionnement et fin de session ;
- isolation des identités et jetons entre tenants ;
- fallback contrôlé du mode délégué vers le compte de service ;
- masquage des données et restrictions d'export.

## 26. Conclusion générale

Le menu « Requêtes partagées » permet de simplifier l'interface, mais il ne remplace pas le modèle d'autorisation. La colonne de visibilité peut disparaître de cette page dédiée, tandis qu'un niveau d'accès explicite doit rester visible dans les vues où plusieurs origines sont mélangées.

L'évolution structurante consiste à passer d'un partage global binaire à un contrôle d'accès combinant propriétaire, utilisateurs, groupes, organisation et tenants autorisés. Ce modèle doit être gouverné par des rôles, une super-administration fortement sécurisée et un audit complet.

Le SSO doit être livré en deux temps : authentification centralisée sur OracleData, puis identité Oracle déléguée uniquement pour les tenants qui la supportent. Cette séparation réduit les risques et permet de conserver un mode hybride pour les automatisations.

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

- protéger l'administration des tenants ;
- mémoïser les tenants ;
- ajouter pagination, recherche serveur et index ;
- configurer timeouts et erreurs Oracle ;
- ajouter métriques de base et identifiants de corrélation.

### Étape 2 — Fondation multilingue FR/EN/ES

- choisir et installer l'architecture i18n ;
- ajouter `locale` et `timezone` au profil ;
- extraire les textes codés en dur ;
- traduire navigation, authentification, bibliothèque et erreurs ;
- mettre en place glossaire, XLIFF et tests de catalogues.

### Étape 3 — Administration et contrôle d'accès

- rôles et permissions ;
- super-administrateur sécurisé ;
- console d'administration ;
- audit des actions ;
- matrice initiale utilisateurs-tenants.

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
- finalisation de la matrice utilisateurs-tenants ;
- preuve de concept OAuth Oracle sur un tenant réel ;
- identité Oracle déléguée pour les tenants compatibles ;
- mode hybride audité pour les jobs et tenants non compatibles ;
- tests complets de révocation, isolation et accès d'urgence.

### Règle de passage entre les étapes

Une étape est terminée uniquement lorsque :

- les critères d'acceptation sont validés ;
- les tests fonctionnels et de sécurité sont verts ;
- les métriques de performance ne régressent pas ;
- la documentation utilisateur et administrateur est à jour ;
- les trois langues sont complètes pour les fonctions livrées ;
- un plan de rollback existe ;
- les décisions techniques importantes sont documentées.
