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
6. Le journal d'exécution enregistre l'utilisateur exécutant, le tenant et la connexion réellement utilisés, et non la cible préférée de l'auteur.
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

Dernière mise à jour : 18 juillet 2026.

| Étape | Sujet | État |
| ---: | --- | --- |
| 1 | Stabilisation et sécurité immédiate | **Fait — 17 juillet 2026** |
| 2 | Fondation multilingue FR/EN/ES | **En cours — 17 juillet 2026** |
| 3 | Tenants personnels, onboarding et contrôle d'accès | **En cours — fondation livrée le 17 juillet 2026** |
| 4 | Bibliothèque organisée | **Fait — 17 juillet 2026** |
| 5 | Partage ciblé et collaboration | **Implémentation terminée — partage, invitations, réponses internes, demandes, commentaires et mentions livrés le 18 juillet 2026 ; validation PHP finale à relancer** |
| 6 | Gouvernance et templates officiels | **En cours — lots 6A et 6B implémentés le 18 juillet 2026 : publication versionnée, comparaison, restauration et certification ; rôles éditoriaux spécialisés et édition Oracle contrôlée restent à livrer, validations PHP/Node et migrations locales `168000`/`169000` à relancer** |
| 7 | Couche sémantique Oracle | À faire — catalogue technique et découverte des champs déjà partiels |
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
- index composites sur propriétaire/niveau d'accès et date de mise à jour ;
- colonne « Visibilité » retirée de la page exclusivement partagée ;
- timeouts Oracle configurables, retries bornés et messages sans fuite technique ;
- identifiant de corrélation et mesure `Server-Timing` sur chaque réponse ;
- journalisation structurée des appels Oracle et requêtes HTTP lentes ;
- migrations de stabilisation locales appliquées et `test@example.com` promu super-administrateur ;
- migration multi-tenant prête, avec arrêt sécurisé si les tenants historiques n'ont pas de propriétaire non ambigu ;
- dernière validation cumulative exécutée avant les lots `167000` et `168000` : 433 tests et 2 595 assertions réussis au 18 juillet 2026 ;
- lot d'invitations différées et centre de notifications interne livré et validé, avec migration locale `166000` appliquée en batch 13 ;
- Pint, PHPStan, ESLint, Prettier, TypeScript et build Vite de production validés jusqu'au lot `166000` ; après les lots `167000` à `169000`, la parité et la validité des catalogues JSON ainsi que les contrôles statiques ciblés sont confirmés, mais la validation finale PHP et Node reste à relancer.

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
- [x] créer catégories et tags traduisibles — catégories et tags officiels FR/EN/ES avec repli français, tags libres réutilisables, sélecteurs create/edit, badges, filtres et console super-admin ;
- [x] créer favoris et épinglage — préférences strictement personnelles, priorité des épingles, mutations optimistes et rollback frontend en cas d'échec ; ce comportement React est implémenté mais reste à couvrir par un test composant ou E2E dédié ;
- [x] ajouter filtres, tris et statistiques à la bibliothèque — recherche nom/description/propriétaire/tags, filtres catégorie/tag/favoris/épingles, six tris serveur et agrégats d'exécution ;
- [x] créer les vues enregistrées — filtres nommés par utilisateur, vue par défaut, validation par liste blanche, application et suppression depuis la bibliothèque ;
- [x] optimiser le dashboard — compteurs, huit semaines et domaines agrégés en SQL, colonnes récentes limitées et métriques mensuelles par utilisateur exécutant ;
- [x] rendre la description d'une requête éditable et durable — champ facultatif limité à 2 000 caractères, prérempli en édition, affiché dans la bibliothèque et le détail, y compris pour les lecteurs d'une requête partagée ; une valeur vide est conservée comme `null` et n'est plus remplacée par un résumé technique automatique.

Résultat de l'étape 4 :

- nouvelles tables `tag_translations`, `query_user_preferences` et `saved_query_views`, appliquées localement en batch 5 avec clés étrangères, contraintes d'unicité, index et migrations réversibles ;
- administration unifiée des catégories et tags réservée au `super_admin`, sans suppression des requêtes lors du retrait d'une taxonomie ;
- catégorie et tags conservés lors du clonage, localisés dans la bibliothèque et la page de détail, et modifiables dans le query builder ;
- favoris et épingles isolés par utilisateur, y compris pour une requête partagée, sans élargir les droits sur sa définition ;
- filtres conservés dans l'URL et vues enregistrées cloisonnées par propriétaire avec refus IDOR en `404` ;
- statistiques de bibliothèque fondées sur les agrégats atomiques et statistiques du dashboard fondées sur l'utilisateur qui a réellement exécuté la requête ; le test anti-N+1 maintient le chargement du dashboard à treize requêtes SQL au plus avec cinquante requêtes accessibles, dont deux requêtes bornées pour les groupes et leurs grants applicables et une pour le compteur de notifications non lues ;
- 318 tests et 1 510 assertions réussis à la clôture initiale de l'étape 4 ; Pint, PHPStan, ESLint, Prettier, TypeScript et build Vite de production validés.

Progression de l'étape 5 — **périmètre collaboratif interne implémenté le 18 juillet 2026** :

- [x] remplacer le partage binaire par les niveaux `private`, `restricted` et `organization` ;
- [x] permettre le partage direct avec un utilisateur, avec acceptation immédiate dans ce premier lot ;
- [x] appliquer les permissions cumulatives `view`, `execute`, `clone` et `manage` dans les Policies et les scopes d'accès ;
- [x] gérer la date d'expiration, la révocation et l'historique des partages directs ;
- [x] journaliser les créations, changements de permission, révocations et changements de niveau d'accès sans exposer de secret ;
- [x] centraliser les règles d'accès utilisées par la bibliothèque, le dashboard, le détail, l'exécution et le clonage ;
- [x] garantir qu'une requête partagée s'exécute uniquement avec une connexion Oracle active appartenant au lecteur ;
- [x] ajouter les groupes et le partage d'équipe, avec gestion des membres et des rôles `owner`, `manager` et `member` ;
- [x] archiver logiquement une requête supprimée, révoquer atomiquement ses grants actifs et conserver les cycles de partage et d'exécution ;
- [x] ajouter les invitations nominatives avec acceptation différée et le centre de notifications interne ;
- [x] notifier une seule fois l'émetteur lors de l'acceptation ou du refus réel d'une invitation, sans dupliquer l'événement lors d'un rejeu ;
- [x] ajouter les demandes de modification avec états gouvernés, commentaires immuables et mentions structurées limitées aux lecteurs actuels ;
- [x] conserver les contenus des messages hors de l'audit et des notifications techniques ; une mention n'accorde jamais un accès ;
- [ ] relancer les tests PHP et appliquer la migration locale `167000` dès que le runner Herd redevient disponible.

Résultat courant de l'étape 5 :

- `private` limite la requête à son propriétaire, `restricted` l'ouvre uniquement aux personnes ou groupes disposant d'un grant actif et `organization` la rend accessible aux utilisateurs autorisés de la plateforme ;
- les droits sont cumulatifs : `execute` inclut `view`, `clone` inclut `execute` et `view`, et `manage` permet en plus d'administrer les accès ordinaires sans transférer la propriété de la définition ; les grants de groupe sont volontairement plafonnés à `clone`, donc `manage` reste exclusivement nominatif ;
- seul le propriétaire peut déléguer `manage` ou changer la portée globale ; un gestionnaire délégué ne peut ni modifier son propre grant, ni administrer un autre grant `manage`, ni accorder une expiration postérieure à la sienne ;
- le propriétaire, ou un délégataire disposant de `manage` dans ces limites, peut partager avec une personne ou un groupe, modifier une permission, fixer une expiration ou révoquer un accès depuis « Gérer le partage » ;
- l'écran « Groupes » (`/groups`) permet de créer et décrire une équipe, d'en gérer les membres selon le rôle courant, de la renommer ou de l'archiver lorsque l'utilisateur en est propriétaire ;
- le sélecteur de « Gérer le partage » bascule entre « Personne » et « Groupe » et présente dans la même interface les grants actifs, l'expiration, la révocation et l'historique de chaque type de destinataire ;
- l'appartenance courante au groupe détermine l'accès hérité : retirer un membre lui retire immédiatement cet accès, sans modifier le grant du groupe pour les autres membres ;
- l'archivage logique d'un groupe révoque ses grants actifs dans la même transaction, tout en conservant les cycles historiques et le nom du groupe figé dans chaque partage ;
- la suppression d'une requête par son propriétaire est désormais un archivage logique : ses grants directs et de groupe actifs sont révoqués et audités dans la transaction, tandis que les cycles de partage et les exécutions restent conservés ; une restauration technique ne réactive aucun grant ;
- le passage à `private` ferme à la fois les accès directs et les accès de groupe actifs, tandis que les partages expirés ou révoqués restent consultables dans l'historique ;
- pour une personne inscrite, « Gérer le partage » crée désormais par défaut une invitation `pending` avec permission, expiration éventuelle du futur accès et date limite de réponse ; cette ligne ne donne aucun droit et une requête `private` reste privée tant que le destinataire n'a pas accepté ;
- l'acceptation transforme la même ligne `query_user_shares` en grant `accepted` et place atomiquement une requête encore privée en `restricted` ; le refus conserve le cycle en `declined`, tandis qu'une annulation par le gestionnaire le conserve en `cancelled` ;
- l'expiration d'une invitation est calculée à partir de `respond_by` et de l'expiration éventuelle du futur accès, sans inventer un sixième statut persistant ; une invitation expirée ne peut ni être acceptée ni ouvrir un accès ;
- l'autorité du délégataire qui a émis l'invitation est recalculée au moment de l'acceptation ; si son grant `manage` a disparu, a expiré ou ne couvre plus la permission et la durée proposées, l'invitation est annulée sans ouvrir la requête ;
- le retour à `private` et l'archivage logique d'une requête annulent dans leur transaction toutes ses invitations encore valides, en plus de révoquer les grants actifs ;
- les groupes conservent leur parcours immédiat : l'acceptation différée concerne uniquement un destinataire utilisateur déjà inscrit sur la plateforme ;
- le centre `/notifications` est paginé, filtrable sur les éléments non lus et permet de marquer un élément ou toute la boîte comme lus ; un compteur non lu alimente la cloche de navigation et le destinataire peut accepter ou refuser depuis le centre ;
- la notification interne est écrite de façon synchrone dans la même transaction que l'invitation et ne conserve que des identifiants techniques ; les noms affichés sont résolus côté serveur à partir des relations autorisées ;
- les événements `query.share_invited`, `query.share_invitation_accepted`, `query.share_invitation_declined` et `query.share_invitation_cancelled` conservent uniquement les identifiants, permissions, échéances et motifs techniques autorisés par `AuditRecorder` ;
- « Partagées avec moi » exclut les requêtes du lecteur lui-même et reflète les mêmes règles que le détail, l'exécution et le clonage ;
- partager la définition ne partage jamais le tenant, la connexion ni les identifiants Oracle du propriétaire ;
- l'émetteur reçoit maintenant une notification interne normalisée lors d'une acceptation ou d'un refus effectif ; les rejeux idempotents ne créent ni nouvel audit ni nouvelle notification ;
- tout lecteur actuel non propriétaire peut ouvrir une demande de modification ; le propriétaire décide des passages `pending -> accepted|rejected`, puis `accepted -> completed|cancelled`, tandis que le demandeur peut annuler sa demande encore en attente ;
- chaque demande possède un fil borné de commentaires immuables ; les lecteurs actuels peuvent le suivre tant que la requête reste accessible et les états terminaux ferment les nouveaux commentaires ;
- les mentions sont structurées par identifiants, limitées à dix personnes par message, validées contre la Policy de lecture courante et dédupliquées des notifications ordinaires ; elles ne créent aucun grant ;
- le centre interne distingue création, commentaire, mention et changement d'état, résout les noms depuis les relations encore autorisées et ne persiste jamais le titre, le message ou le commentaire dans la notification ;
- les notifications e-mail restent planifiées avec la queue à l'étape 9 et les connecteurs externes à l'étape 12 ; ils ne font plus partie du critère de clôture de la collaboration interne synchrone ;
- la migration `162000` introduit les niveaux d'accès et les partages directs en lot local 10 ; la migration de compatibilité `163000`, appliquée en lot local 11, remplace sans perte la contrainte unique historique par l'index `(query_id, user_id, status)` afin de conserver chaque cycle de partage ;
- la migration `164000` introduit `groups`, `group_user` et `query_group_shares`, avec suppression logique des groupes, appartenance unique, snapshots de nom et index de résolution du cycle de vie ; elle est appliquée localement en batch 12 ;
- la migration `165000`, également appliquée localement en batch 12, ajoute la suppression logique aux requêtes afin qu'une suppression fonctionnelle ne déclenche plus les cascades physiques sur leurs historiques ;
- la migration `166000` étend `query_user_shares` avec les états et dates du parcours différé, ajoute ses index de résolution et crée la table Laravel `notifications` ; elle est appliquée localement en batch 13 et son schéma a été contrôlé ;
- la migration `167000` crée `query_change_requests`, le fil `query_change_request_comments` et la table de mentions ; elle est prête mais son application locale reste à exécuter, le runner PHP externe ayant été refusé par l'environnement de travail ;
- 14 scénarios dédiés au partage direct couvrent notamment les permissions HTTP, l'IDOR, l'expiration, la révocation, le repartage durable, le passage en privé, les limites d'un gestionnaire délégué et l'absence de données sensibles dans l'audit ;
- 16 scénarios dédiés aux groupes et au partage d'équipe couvrent la gouvernance des rôles, l'isolation IDOR, la résolution du droit le plus fort, l'expiration, le retrait immédiat d'un membre, l'archivage, le repartage durable, la révocation globale lors du passage en privé et l'audit sans données sensibles ;
- l'archivage des requêtes est couvert par la conservation des cycles, la révocation atomique, l'audit sans contenu sensible, le refus d'un délégataire `manage` et l'absence de réactivation après restauration technique ;
- les suites dédiées `QueryShareInvitationTest` et `NotificationCenterTest` couvrent l'absence d'accès avant consentement, l'acceptation et le refus idempotents, l'expiration calculée, l'IDOR, la perte d'autorité de l'émetteur, l'annulation lors du retour à `private` ou de l'archivage, l'isolation des notifications et leur payload technique ;
- neuf nouveaux scénarios `QueryChangeRequestTest` couvrent création autorisée, IDOR, retrait d'accès, commentaires immuables, mentions autorisées, déduplication, transitions, rejeux idempotents, archivage atomique, masquage après perte d'accès, audit sans contenu et présentation normalisée dans le centre interne ;
- dernière validation cumulative effectivement exécutée avant ces lots : 433 tests et 2 595 assertions réussis ; TypeScript, ESLint et Prettier ciblés du frontend collaboratif sont réussis. Pour les consoles 6A/6B, la validité et la parité des catalogues JSON FR/EN/ES ainsi que la revue statique ciblée sont confirmées, mais les contrôles Node complets, Pint, PHPStan et les tests PHP restent à relancer sans contournement lorsque les runners seront disponibles.

Progression de l'étape 6 — **socle et lots de gouvernance 6A/6B implémentés le 18 juillet 2026** :

- [x] créer une table système `query_templates` distincte des requêtes personnelles afin que l'original ne puisse jamais être altéré par un utilisateur ;
- [x] exposer une bibliothèque dédiée de modèles actifs avec recherche, catégorie, description et ressource Oracle ;
- [x] permettre l'aperçu et l'exécution avec le tenant du lecteur et des paramètres typés (`number`, `integer`, `date`, `select`, `boolean`, `string`) ;
- [x] lier les valeurs côté serveur à des champs, opérateurs et paramètres d'exécution placés sur liste blanche, sans laisser le navigateur construire librement le filtre Oracle ;
- [x] permettre le clonage en requête privée et modifiable, en matérialisant les paramètres choisis et en conservant la provenance avec `queries.query_template_id` ;
- [x] garantir l'immutabilité applicative du modèle original et vérifier que l'aperçu, l'exécution et le clonage ne le modifient pas ;
- [x] fournir quatre modèles initiaux par seeder idempotent, dont les factures fournisseurs supérieures à un montant choisi ;
- [x] journaliser l'exécution et le clonage sans élargir l'accès aux connexions Oracle d'un autre utilisateur ;
- [x] traduire les noms, descriptions, options et aides des contenus officiels en FR/EN/ES — 12 traductions seedées pour les quatre modèles, avec repli champ par champ `locale -> fr -> contenu source` sans modifier les valeurs techniques ;
- [x] enregistrer les aperçus et exécutions de modèles dans `query_executions` avec source, finalité, durée, statut, nombre de lignes, tenant et connexion ; le dashboard exclut explicitement les aperçus ;
- [x] définir pour chaque paramètre une politique d'audit (`clear`, `masked`, `hmac` versionné ou `omit`) — toute politique absente ou inconnue échoue de manière fermée vers `omit` et aucune valeur brute masquée, empreintée ou omise n'est conservée ;
- [x] faire échouer l'exécution de manière fermée lorsqu'un fallback Oracle retirerait ou modifierait un filtre `q` — l'invariant s'applique à toutes les tentatives, aux retries sans projection et aux jointures ; les modèles et leurs clones utilisent en plus la politique `exact` ;
- [x] ajouter une console super-administrateur avec brouillon, revue, publication, archivage, propriétaire métier et date de révision ;
- [x] créer un historique immuable des versions officielles, publier atomiquement une projection et marquer la version précédente comme remplacée ;
- [x] ajouter un verrouillage optimiste du modèle et de la version, ainsi qu'une contrainte empêchant deux cycles éditoriaux ouverts en parallèle ;
- [x] rattacher chaque clone, aperçu et exécution à la version officielle exacte capturée avant tout appel Oracle ;
- [x] empêcher le seeder de réécrire une projection déjà gouvernée ; il initialise uniquement les modèles officiels encore absents ;
- [x] ajouter la comparaison visuelle de deux versions exactes, la restauration sous forme d'une nouvelle version et la certification post-publication ;
- [ ] ouvrir, dans un lot ultérieur, l'édition contrôlée de la définition Oracle technique et la création de nouveaux modèles officiels.

Résultat courant de l'étape 6 :

- les modèles globaux sont indépendants de tout propriétaire utilisateur et seuls les modèles actifs sont visibles ;
- l'aperçu est borné à 25 lignes, tandis que l'exécution respecte la limite validée du modèle ;
- les paramètres inconnus, valeurs hors domaine, structures de requête injectées et tenants non accessibles sont refusés avant l'appel Oracle ;
- le clone reçoit sa propre description éditable, sa catégorie, ses paramètres résolus et sa visibilité privée sans modifier le modèle source ;
- chaque aperçu ou exécution directe possède une ligne statistique reliée au modèle, sans incrémenter les agrégats d'une requête personnelle ; seuls les `purpose=run` alimentent les statistiques mensuelles du dashboard ;
- les événements d'exécution et de clonage ne contiennent que les paramètres autorisés par leur politique d'audit ; les clés sensibles simples, composées ou en `camelCase` restent refusées par le point d'entrée global d'audit ;
- les modèles existants reçoivent une version 1 publiée contenant leur définition et leurs traductions ; le catalogue public continue d'utiliser uniquement le pointeur `published_version_id` et reste inchangé pendant un brouillon ou une revue ;
- une publication fait passer la version revue à `published`, l'ancienne à `superseded`, puis projette dans la même transaction le contenu validé et les traductions FR/EN/ES ; une version soumise ne peut plus être modifiée ni supprimée ;
- l'archivage retire le modèle de la bibliothèque sans supprimer ses versions et ferme proprement tout cycle encore ouvert ;
- la comparaison charge explicitement deux versions du même modèle, y compris hors de la page d'historique courante, puis produit un diff canonique réparti entre métadonnées, traductions et définition technique ; cette lecture n'écrit ni audit ni état serveur et refuse tout identifiant appartenant à un autre modèle ;
- la restauration ne remplace jamais la projection publique : elle copie un ancien snapshot réellement publié et désormais `superseded` dans un nouveau brouillon numéroté, conserve `restored_from_version_id`, revalide son empreinte et le catalogue actuel, puis impose le workflow normal avant toute publication ;
- la certification est une attestation distincte du statut « Template officiel » et intervient après publication : elle référence la version publiée exacte et son empreinte, exige un propriétaire métier ainsi qu'une date de révision non dépassée, et peut exposer une note publique facultative limitée à 500 caractères ;
- une seule certification peut être active par modèle ; elle reste dans l'historique mais cesse d'être effective publiquement dès que le pointeur publié, l'empreinte, le propriétaire ou la date de révision ne correspondent plus, et toute nouvelle publication ou tout archivage la révoque atomiquement ; une révocation manuelle motivée reste également possible ;
- la bibliothèque et le détail publics affichent un badge « Certifiée » distinct et la note publique uniquement lorsque l'attestation reste effective ; la console conserve l'attestation devenue non effective pour permettre sa compréhension et sa révocation ;
- les champs `business_owner_user_id`, `review_due_at`, `published_at/by`, `archived_at/by` et les verrous optimistes rendent la responsabilité et les échéances explicites ; une échéance n'est en retard qu'à partir du lendemain ;
- la provenance `query_template_version_id` est conservée sur les copies personnelles et leurs exécutions ultérieures, ainsi que sur les aperçus et exécutions directes ;
- les opérations publiques capturent sous un verrou court le snapshot publié puis libèrent le verrou avant l'appel Oracle ; définition, traductions et numéro de version ne peuvent donc pas provenir de deux publications concurrentes différentes ;
- la console 6A/6B permet de gouverner les métadonnées et contenus trilingues, l'historique, la restauration et la certification ; la structure Oracle et les liaisons de paramètres restent en lecture seule afin de ne pas contourner la validation technique ;
- les migrations de traductions, de métriques de modèles et de politique d'exécution sont appliquées localement en batch 9 ; la base contient quatre modèles et douze traductions officielles ;
- 19 scénarios dédiés aux modèles couvrent notamment visibilité, localisation, repli de langue, isolation des tenants, liaison typée, échappement, limites, métriques, audit, clonage, immutabilité et idempotence du seeder ; ils sont complétés par les tests de sécurité du moteur Oracle ;
- dix nouveaux scénarios de gouvernance couvrent la Policy super-administrateur, la publication atomique, le cycle unique, les conflits Inertia actionnables, les verrous obsolètes, l'IDOR de version, la validation de snapshot, l'immutabilité, l'archivage, le seeder, la reprise d'un bootstrap interrompu, la cohérence définition/traductions en concurrence et la provenance des exécutions clonées ;
- neuf scénarios supplémentaires de gouvernance 6B couvrent la restauration et sa provenance, les verrous obsolètes, le cycle ouvert, les versions jamais publiées, les snapshots altérés, la comparaison exacte et sans écriture, l'IDOR, les prérequis de certification, les conflits imbriqués, la révocation manuelle ou automatique et l'expiration publique liée à la date de révision ; ces scénarios sont écrits mais n'ont pas encore été exécutés ;
- les migrations `168000` et `169000` sont prêtes mais ne sont pas encore appliquées localement ; `169000` ajoute la provenance des restaurations et l'historique immuable des certifications. Les 709 clés de chacun des catalogues frontend FR/EN/ES, leurs paramètres et les catalogues backend sont valides et paritaires, et `git diff --check` réussit ; Pint, PHPStan, les tests PHP, TypeScript, ESLint, Prettier et le build Vite finaux restent à relancer dès disponibilité des runners.

La collaboration interne de l'**étape 5** est maintenant complète dans le code : partage direct et par groupes, invitations différées, réponses vers l'émetteur, demandes de modification, commentaires et mentions convergent dans le même centre interne sans partager les connexions Oracle. Les canaux e-mail et connecteurs sont volontairement rattachés aux étapes 9 et 12. L'**étape 6** possède désormais une publication versionnée réelle, une comparaison exacte, une restauration non destructive et une certification post-publication ; elle reste en cours uniquement pour les rôles éditoriaux spécialisés et l'édition contrôlée de la définition Oracle. Les migrations locales et les validations finales PHP et Node des lots `167000` à `169000` doivent être exécutées dès que les runners sont de nouveau autorisés.

Périmètre analysé par ce document, qu'il soit déjà livré ou encore planifié :

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

- Laravel 13 et PHP `>= 8.3` ; le runtime local de validation utilise PHP 8.4 ;
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

### 3.1 Bibliothèque non paginée — corrigé le 17 juillet 2026

La bibliothèque charge désormais 25 requêtes par page, sélectionne seulement les colonnes utiles et applique recherche, filtres, tris, favoris et épinglage côté serveur. Les paramètres sont conservés dans l'URL et la recherche React est différée de 350 ms.

Recommandations :

- pagination serveur de 25 ou 50 éléments ;
- recherche et filtres côté serveur ;
- sélection des seules colonnes nécessaires ;
- conservation des filtres dans l'URL ;
- debounce de la recherche.

### 3.2 Lectures répétées des tenants — corrigé le 17 juillet 2026

`FusionManager` peut relire la table des tenants chaque fois qu'un libellé est demandé. Dans une liste, cela peut produire un N+1 et, si le cache n'est pas correctement segmenté, une fuite de métadonnées entre utilisateurs.

Recommandation : mémoïser la liste des tenants du seul utilisateur courant pendant toute la requête HTTP, inclure son `user_id` dans toute clé de cache persistante et invalider ce cache après création, modification ou suppression d'un tenant.

État actuel : `FusionManager` est désormais résolu dans le périmètre de l'utilisateur courant, mémoïsé pendant la requête et invalidé après mutation. Les tests d'isolation empêchent la réutilisation de la liste ou des connexions d'un autre utilisateur.

### 3.3 Index insuffisants — corrigé pour les parcours actuels le 17 juillet 2026

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

Ces index sont présents dans les migrations actuelles, notamment sur la bibliothèque, les catégories et les exécutions. Leur ordre devra encore être confirmé avec les plans d'exécution et la volumétrie réels de la base de production.

### 3.4 Grands tableaux de résultats

Le tri, le filtrage, le rendu et l'export CSV sont actuellement réalisés dans le navigateur.

Recommandations :

- traitement local pour les petits résultats ;
- pagination ou virtualisation au-delà d'un seuil ;
- export CSV côté serveur pour les gros volumes ;
- chargement des sous-tableaux Oracle uniquement à leur ouverture ;
- limite maximale de lignes renvoyées au navigateur.

### 3.5 Dashboard — corrigé le 17 juillet 2026

Les compteurs de bibliothèque et les huit semaines sont regroupés dans une agrégation SQL portable. La répartition par domaine utilise l'extraction JSON adaptée à SQLite, MySQL/MariaDB ou PostgreSQL, puis un `GROUP BY` SQL. Les statistiques mensuelles d'exécution sont filtrées par l'utilisateur exécutant et les requêtes récentes ne sélectionnent que leurs colonnes d'affichage.

Recommandations :

- agrégations simples en SQL ;
- colonnes dédiées pour les classifications fréquemment filtrées ;
- agrégats pré-calculés pour les exécutions ;
- cache court pour les indicateurs peu changeants.

### 3.6 Appels Oracle — socle de fiabilité livré les 17 et 18 juillet 2026

Les appels Oracle doivent recevoir :

- un timeout de connexion court ;
- un timeout global explicite ;
- un retry limité aux erreurs transitoires ;
- une journalisation de la durée et du statut ;
- des erreurs normalisées sans secret ni détail technique sensible ;
- un identifiant de corrélation pour le support.

Le client Oracle applique maintenant des délais de connexion et d'exécution configurables, des retries bornés aux erreurs transitoires, une journalisation structurée et les identifiants de corrélation de la requête HTTP.

La découverte dynamique des champs du query builder est également livrée :

- fallback immédiat vers le catalogue statique pour ne pas bloquer l'interface ;
- sondage Oracle puis cache persistant `oracle_resource_fields`, isolé par tenant, ressource et enfant ;
- commandes de préchauffage et d'invalidation du cache de schéma ;
- cache frontend segmenté par utilisateur, tenant, ressource et enfant ;
- déduplication des appels simultanés, timeout et retry borné côté navigateur ;
- utilisation des champs découverts par l'interface et la validation backend des requêtes.

### 3.7 Analyses agent synchrones

Une analyse agent peut réaliser plusieurs cycles LLM et plusieurs appels Oracle dans la même requête HTTP.

À terme, elle devrait être placée dans une queue avec :

- états `queued`, `running`, `completed`, `failed` et `cancelled` ;
- polling léger ou flux serveur ;
- progression visible ;
- délai maximal et limite de coût ;
- annulation lorsque possible.

### 3.8 Bundle frontend

Mesures observées lors du build de production validé le 18 juillet 2026 :

- bundle principal : 237,2 Ko, soit 66,6 Ko gzip ;
- bibliothèque de requêtes : 23,3 Ko, soit 7,3 Ko gzip ;
- query builder chargé séparément : 61,2 Ko, soit 21,5 Ko gzip ;
- bibliothèque des modèles prédéfinis : 4,8 Ko, soit 2,2 Ko gzip ;
- console catégories/tags : 11,1 Ko, soit 3,4 Ko gzip ;
- chunk Wayfinder : 318,8 Ko, soit 100,5 Ko gzip ;
- CSS : 105,9 Ko, soit 17,2 Ko gzip.

Le chunk Wayfinder doit être analysé : vérifier le tree-shaking, limiter les routes générées si possible et confirmer les dépendances réellement chargées au premier affichage. Les composants lourds du query builder peuvent aussi être chargés à la demande.

### 3.9 Limites actuelles à conserver dans le backlog

- l'enregistrement d'une modification de métadonnées dans le query builder, y compris la description, reste conditionné à un aperçu Oracle réussi ; une mise à jour partielle dédiée sera nécessaire pour rendre ces modifications indépendantes de la disponibilité d'Oracle ;
- la bibliothèque des modèles charge actuellement tous les modèles actifs et applique recherche et catégorie dans le navigateur ; ajouter pagination et recherche serveur avant une croissance importante du catalogue ;
- plusieurs textes du query builder, des breadcrumbs et de l'affichage des résultats restent codés en français malgré la fondation i18n ;
- le générateur SQL actuel produit du SQL pour BI Publisher à partir d'une configuration déjà structurée ; il ne constitue pas le futur traducteur de SQL standard vers un plan d'appels API ;
- les lots de console 6A/6B, le versionnement, la comparaison, la restauration non destructive, la certification, le propriétaire métier et la date de révision sont implémentés ; des rôles éditoriaux plus fins que `super_admin` et l'édition Oracle contrôlée restent à livrer ;
- la durée de rétention et la purge contrôlée de `query_executions` et `audit_events` restent à formaliser avant une volumétrie de production importante ;
- l'observabilité devra distinguer les incompatibilités Oracle bloquées par la politique `exact` des erreurs réseau, sans journaliser le filtre ni ses valeurs ;
- le chunk Wayfinder reste le principal poste frontend à analyser.

## 4. Tags et catégories

### Modèle recommandé

```text
categories
- id
- slug unique
- color
- timestamps

category_translations
- category_id
- locale: fr | en | es
- name
- description nullable

tags
- id
- name, libellé source et repli des tags libres
- slug unique
- timestamps

tag_translations
- tag_id
- locale: fr | en | es
- name

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
- administration des catégories et tags officiels selon les permissions.

### État livré le 17 juillet 2026

- la catégorie principale et les tags sont sélectionnables à la création et à la modification d'une requête ;
- les catégories et tags officiels utilisent les tables de traduction avec repli `locale active -> fr -> slug ou nom source` ;
- les tags libres restent possibles et sont normalisés par slug pour éviter les doublons ;
- la recherche couvre aussi le propriétaire et les traductions de tags ;
- le clonage conserve catégorie et tags, tandis que leur suppression administrative conserve toujours la requête ;
- la console « Catégories et tags » est visible uniquement par le `super_admin` et les routes restent protégées par le Gate backend.

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

### État livré le 17 juillet 2026

`query_user_preferences` respecte la contrainte unique `(user_id, query_id)` et les suppressions en cascade. Le lecteur d'une requête partagée possède ses propres favoris et épingles, sans modifier la requête ni les préférences de l'auteur. Les actions React sont optimistes et restaurent l'état précédent avec une notification si le serveur refuse la mutation. La suite actuelle valide le contrat backend et le build frontend, mais pas encore ce rollback visuel par un test composant ou E2E.

### Vues enregistrées

```text
saved_query_views
- id
- user_id
- name
- filters JSON
- is_default
- timestamps

Contrainte unique : (user_id, name)
Index : (user_id, is_default)
```

Seuls `scope`, `search`, `category`, `tag`, `sort`, `favorite` et `pinned` sont persistables. Les valeurs sont revalidées côté serveur, chaque mutation est filtrée par propriétaire et une vue étrangère retourne `404`. Une vue par défaut est appliquée à l'ouverture de la bibliothèque lorsque l'URL ne fournit aucun filtre explicite.

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

### État livré le 17 juillet 2026

La livraison synchrone utilise les statuts `succeeded` et `failed`; les états de queue restent réservés à l'étape 9. Chaque exécution enregistre l'utilisateur, son tenant, sa connexion, la durée, le nombre de lignes et un code d'erreur normalisé. Les agrégats atomiques sont affichés dans la bibliothèque avec tri par utilisation ou dernière exécution. Le dashboard expose, pour le mois courant et pour le seul utilisateur exécutant, le nombre d'exécutions, le taux de succès et la durée moyenne.

Extension livrée le 18 juillet 2026 pour les modèles prédéfinis : `query_executions.source_type` distingue une requête enregistrée d'un modèle, `query_template_id` conserve la source directe et `purpose` distingue `preview` de `run`. Le lot 6A ajoute `query_template_version_id`, capturé avant l'appel Oracle, afin qu'un aperçu, une exécution directe ou l'exécution ultérieure d'un clone reste attribuable à la version officielle exacte. Un aperçu est observable sans être compté comme une exécution métier dans le dashboard. L'exécution directe d'un modèle ne modifie jamais les agrégats d'une requête personnelle ; une copie exécutée ultérieurement suit le parcours normal de sa propre requête tout en conservant cette provenance.

## 8. Demandes de modification

### Modèle retenu et implémenté le 18 juillet 2026

```text
query_change_requests
- id
- query_id
- requested_by_user_id nullable
- title
- status: pending | accepted | rejected | completed | cancelled
- status_changed_by_user_id nullable
- status_changed_at nullable
- timestamps

query_change_request_comments
- id
- query_change_request_id
- user_id nullable
- body
- timestamps

query_change_request_comment_mentions
- query_change_request_comment_id
- user_id
- timestamps
- primary (query_change_request_comment_id, user_id)
```

Le titre reste sur la demande, tandis que le message initial, les précisions et la réponse du propriétaire utilisent le même fil immuable. Cette séparation évite des champs spéciaux `owner_response` et conserve une chronologie unique.

### Parcours

1. Un utilisateur clique sur « Demander une modification ».
2. Il décrit le changement souhaité.
3. Le propriétaire reçoit une notification technique interne ; les personnes explicitement mentionnées reçoivent un événement distinct et dédupliqué.
4. Les lecteurs actuels peuvent demander une précision dans le fil tant que l'état est `pending` ou `accepted`.
5. Le propriétaire accepte ou refuse une demande en attente, puis marque une demande acceptée comme terminée ou annulée ; le demandeur peut annuler sa demande encore en attente.
6. Un rejeu vers l'état courant est sans effet et ne duplique ni commentaire, ni audit, ni notification.
7. Une future version de requête personnelle pourra référencer la demande d'origine lorsque ce versionnement sera livré.

Les mentions sont limitées à dix utilisateurs et vérifiées contre l'accès courant à la requête ; elles ne modifient jamais les grants. Les commentaires ne peuvent être ni modifiés ni supprimés. L'audit et les notifications conservent uniquement des identifiants et des états, jamais le titre ou le corps des messages.

Les e-mails devront être envoyés via la queue à l'étape 9 et les intégrations externes via les connecteurs de l'étape 12.

## 9. Templates officiels verrouillés

### Modèle retenu et livré le 18 juillet 2026

Les modèles globaux sont séparés des requêtes personnelles. Cette séparation évite qu'une évolution des droits ou du formulaire utilisateur permette de modifier accidentellement un original officiel.

```text
query_templates
  id
  slug unique
  name
  description nullable
  category_id nullable
  resource_key
  resource_path
  parameters JSON
  parameter_definitions JSON
  is_active
  governance_status
  published_version_id nullable -> query_template_versions.id
  business_owner_user_id nullable -> users.id
  review_due_at nullable
  published_at, published_by_user_id nullable
  archived_at, archived_by_user_id nullable
  lock_version
  sort_order
  timestamps

query_template_versions
  query_template_id -> query_templates.id
  restored_from_version_id nullable -> query_template_versions.id
  version_number
  status: draft | review | published | superseded
  open_slot nullable, unique avec query_template_id
  definition JSON
  translations JSON
  content_hash
  change_summary nullable
  created_by_user_id, submitted_by_user_id, published_by_user_id nullable
  submitted_at, published_at nullable
  lock_version
  timestamps

query_template_certifications
  query_template_id -> query_templates.id
  query_template_version_id -> query_template_versions.id
  version_content_hash
  active_slot nullable, unique avec query_template_id
  certified_by_user_id nullable -> users.id
  certified_at
  public_note nullable, 500 caractères maximum
  revoked_by_user_id nullable -> users.id
  revoked_at, revocation_reason nullable
  lock_version
  timestamps

query_template_translations
  query_template_id -> query_templates.id
  locale
  name
  description nullable
  parameter_labels JSON nullable
  parameter_descriptions JSON nullable
  parameter_options JSON nullable
  unique (query_template_id, locale)

queries
  query_template_id nullable -> query_templates.id
  query_template_version_id nullable -> query_template_versions.id
  execution_policy: best_effort | exact

query_executions
  source_type: saved_query | query_template
  purpose: run | preview
  query_template_id nullable -> query_templates.id
  query_template_version_id nullable -> query_template_versions.id
```

`query_template_versions` porte le snapshot immuable de la définition et des traductions. `restored_from_version_id` conserve la lignée d'une restauration sans réécrire l'historique. `query_templates` reste la projection publique de la seule version publiée. Les traductions ne remplacent que la présentation — nom, description, aides et libellés d'options — tandis que clés, valeurs, types, bornes et liaisons techniques restent issus de la définition source. `queries.query_template_id` et `query_template_version_id` conservent la provenance exacte d'une copie personnelle ; la copie possède ensuite son propre nom, sa description, sa visibilité, son tenant et ses paramètres matérialisés.

Le lot 6A ajoute les statuts de gouvernance et de version `draft`, `review`, `published`, `superseded` et `archived`, ainsi que `published_at`, `published_by`, le propriétaire métier et la date de révision. Ces champs ne sont pas simulés avec `is_active` : celui-ci représente seulement la disponibilité opérationnelle de la projection publiée. Le lot 6B ajoute la lignée des restaurations et des attestations de certification immuables, liées à la version exacte et à son empreinte plutôt qu'au seul état mutable du modèle.

### Règles

- un modèle actif est visible par les utilisateurs authentifiés, vérifiés et ayant terminé l'onboarding ;
- un utilisateur standard ne peut ni le modifier, ni le supprimer, ni changer sa visibilité ;
- chaque utilisateur l'exécute uniquement avec l'une de ses propres connexions Oracle ;
- les valeurs personnalisées sont validées et liées côté serveur à une structure placée sur liste blanche ;
- un filtre `q` validé ne peut être ni supprimé ni modifié par un fallback, un retry ou une jointure ; les exécutions de modèles utilisent la politique `exact` ;
- chaque définition de paramètre déclare ce que l'audit peut conserver, avec omission par défaut en cas d'absence ou d'erreur de configuration ;
- tous les utilisateurs autorisés peuvent le prévisualiser, l'exécuter ou le cloner ;
- le clone devient une requête utilisateur privée et modifiable ;
- le clone conserve un lien de provenance, mais sa modification n'altère jamais le modèle ;
- le seeder initialise uniquement un modèle absent et ne réécrit jamais une projection déjà gouvernée ;
- toute modification officielle depuis la console crée une version distincte et seuls les super-administrateurs peuvent soumettre, publier ou archiver ;
- un brouillon ou une revue n'altère jamais le modèle visible par les utilisateurs ; seule une publication projette atomiquement le snapshot validé ;
- la comparaison est une lecture déterministe de deux versions appartenant au même modèle et ne produit aucun audit ni effet de bord ;
- restaurer une ancienne publication crée toujours un nouveau brouillon avec sa provenance ; la projection courante et l'ancienne version restent intactes ;
- une version jamais publiée, la version publique courante, un snapshot dont l'empreinte a été altérée ou un modèle possédant déjà un cycle ouvert ne peuvent pas être restaurés ;
- la certification est une attestation post-publication distincte du workflow éditorial : elle exige la version publique exacte, son empreinte valide, un propriétaire métier et une date de révision non dépassée ;
- une seule certification est active à la fois ; sa publication publique échoue de manière fermée si le modèle, la version, l'empreinte ou l'échéance ne correspondent plus, sans effacer l'historique ;
- une nouvelle publication ou l'archivage révoque atomiquement l'attestation active ; un super-administrateur peut aussi la révoquer manuellement avec un verrou optimiste.

L'autorité publique repose sur les middlewares d'authentification, de vérification et d'onboarding, sur `ensureActive()` et sur les protections du modèle Eloquent. La console éditoriale possède maintenant une `QueryTemplatePolicy` explicite réservée au `super_admin`, vérifie l'appartenance des versions imbriquées et applique ses règles côté service transactionnel ; masquer un bouton React n'est jamais considéré comme une autorisation.

### Interface

- badge « Template officiel » ;
- icône de verrouillage ;
- version publiée et cycle éditorial courant ;
- boutons « Prévisualiser », « Exécuter » et « Créer ma version » ;
- recherche et filtre par catégorie dans une section dédiée ;
- formulaire de paramètres typés avec valeurs par défaut, contraintes et aide ;
- choix limité aux environnements Oracle du lecteur ;
- rappel visible que l'original est immuable ;
- sélecteurs permettant de comparer deux versions exactes, avec sections métadonnées, traductions et technique ;
- action de restauration uniquement sur une ancienne version publiée et remplacée, avec confirmation et résumé facultatif ;
- carte de certification présentant les prérequis, la note publique, l'état effectif et la révocation ;
- badge « Certifiée » distinct du badge « Template officiel » dans la bibliothèque et le détail publics.

### État livré le 18 juillet 2026

La bibliothèque dédiée, les quatre modèles initiaux et leurs contenus FR/EN/ES, la liaison typée et contrôlée des paramètres, l'aperçu, l'exécution mesurée et auditée ainsi que le clonage privé sont opérationnels. Le modèle original est protégé contre les mises à jour et suppressions applicatives. Les copies personnelles sont créées en politique `exact`, peuvent être modifiées et conservent une description adaptée à la langue active sans toucher à l'original. La console permet également de comparer l'historique complet, de restaurer une publication remplacée sous forme d'un nouveau brouillon et de certifier la publication exacte sans confondre confiance métier et caractère officiel. L'ajout de nouveaux modèles reste conditionné à une politique d'audit explicite et à une exécution qui échoue de manière fermée plutôt que de retirer silencieusement un filtre pour contourner une incompatibilité Oracle.

Le périmètre restant de cette section est la délégation éditoriale : rôles spécialisés plus fins que `super_admin`, responsable technique et édition contrôlée de la définition Oracle.

## 10. Architecture cible

```text
users
  ├── oracle_tenants
  │     └── auth_connections
  ├── queries
  ├── query_user_preferences
  ├── query_executions
  └── query_change_requests

query_templates
  ├── category
  ├── translations FR/EN/ES
  ├── query_template_versions immuables
  │     └── lignée de restauration entre versions
  ├── pointeur vers la version publiée
  ├── historique des certifications lié aux versions et empreintes exactes
  ├── propriétaire métier et date de révision
  ├── paramètres et définitions placés sur liste blanche
  ├── query_executions directes
  └── copies personnelles via queries.query_template_id + query_template_version_id

oracle_tenants
  ├── user propriétaire
  ├── auth_connections
  └── queries du propriétaire qui le prennent comme cible préférée

queries
  ├── category
  ├── tags via query_tag
  ├── query_template source nullable
  ├── query_versions
  ├── query_executions
  ├── query_change_requests
  │     └── comments + mentions
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
- appliquer la suppression logique livrée aux requêtes et aux groupes ; réserver toute purge physique future à une politique de rétention explicite.
- considérer une invitation `pending` comme un message sans droit d'accès, revalider son émetteur lors de l'acceptation et borner toute réponse au destinataire authentifié ;
- limiter les notifications persistées aux identifiants techniques, isoler leur lecture par propriétaire et définir leur durée de rétention avant la production.

## 14. Feuille de route

Cette feuille de route initiale est conservée comme historique de l'audit. La section 29 est la feuille de route maîtresse et fait autorité en cas d'écart.

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
2. Ajouter les notifications internes — centre paginé, compteur non lu et réponse du destinataire depuis le centre livrés et validés.
3. Envoyer les notifications externes via la queue.
4. Lier les demandes acceptées aux versions produites.

### Phase 5 — Exécution avancée

1. Passer les analyses agent dans la queue.
2. Afficher la progression et permettre l'annulation.
3. Déplacer les gros exports côté serveur.
4. Virtualiser les grands tableaux.
5. Auditer et réduire le bundle frontend.

## 15. Tests à prévoir

Cette section mélange les régressions déjà automatisées et le backlog restant. Les états détaillés du suivi d'avancement et les critères de passage de la section 29 déterminent ce qui est réellement livré.

### Backend

- pagination et filtrage des requêtes accessibles ;
- absence de N+1 sur les tenants ;
- création d'un tenant limitée à son utilisateur propriétaire ;
- refus des accès croisés par identifiant deviné ;
- onboarding impossible sans connexion Basic testée, active, vérifiée et par défaut ;
- refus de désactiver ou supprimer la dernière connexion active ;
- exécution d'une requête partagée avec la connexion du lecteur uniquement ;
- gouvernance des groupes, isolation des routes, rôles des membres et retrait immédiat des accès hérités ;
- cycle de vie des grants de groupe, droit effectif cumulatif, archivage logique et passage global à `private` ;
- archivage transactionnel d'une requête, révocation des grants, conservation des cycles et absence de réactivation après restauration technique ;
- invitations utilisateur sans accès avant consentement, acceptation et refus idempotents, expiration calculée, revalidation du délégataire et annulation transactionnelle lors du retour à `private` ou de l'archivage ;
- isolation `404` des réponses aux invitations et des notifications, lecture unitaire ou globale et payload interne limité aux identifiants techniques ;
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
- préremplissage, conservation et effacement vers `null` de la description d'une requête ;
- payload de création et de mise à jour incluant la description personnalisée ;
- recherche, configuration, aperçu, exécution et clonage d'un modèle prédéfini ;
- affichage des erreurs de paramètres et de clonage ;
- gestion des groupes, membres et rôles depuis `/groups` ;
- bascule « Personne »/« Groupe », pagination, expiration, révocation et historique dans « Gérer le partage » ;
- invitations nominatives en attente avec date limite de réponse et annulation depuis « Gérer le partage » ;
- centre de notifications paginé avec filtre non lu, compteur dans la navigation, acceptation, refus et marquage comme lu ;
- cache, déduplication, timeout et retry de la découverte des champs ;
- sérialisation canonique des champs, expansions, jointures et `child_fields` ;
- parcours clavier et accessibilité du formulaire de paramètres ;
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

Décision appliquée dans les lots de partage et de collaboration de l'étape 5 :

- conserver l'information d'accès dans le modèle et les Policies ;
- retirer la colonne de la page dédiée « Requêtes partagées » ;
- afficher un badge compact dans les vues mixtes et « Mes requêtes » ;
- afficher les actions réellement autorisées sur la page de détail et les destinataires dans « Gérer le partage » ;
- déplacer le changement d'accès dans une action explicite « Gérer le partage » ;
- éviter qu'un simple clic sur un badge change accidentellement les droits.

### Remplacement livré du binaire `private/shared`

La migration remplace le champ historique par :

```text
access_level
- private       propriétaire uniquement
- restricted    personnes et groupes explicitement autorisés
- organization  tous les utilisateurs autorisés de la plateforme
```

Les templates officiels utilisent en complément leur statut de publication et leurs permissions administratives.

### Affichage selon la page

| Page | Affichage recommandé |
| --- | --- |
| Mes requêtes | Badge Privée, Restreinte ou Organisation et action « Gérer le partage » |
| Requêtes partagées | Pas de colonne de visibilité ; propriétaire et origine du partage |
| Toutes les requêtes | Badge compact pour comprendre l'origine de l'accès |
| Détail | Niveau d'accès, droits effectifs et actions autorisées |
| Gérer le partage | Destinataires actifs, permission, expiration, révocation et historique |
| Administration | Niveau d'accès, propriétaire, destinataires et historique |

## 19. Partage ciblé avec des utilisateurs et des groupes

### Objectif

Les lots de partage permettent de partager une requête avec une ou plusieurs personnes, avec un ou plusieurs groupes, ou avec toute l'organisation. Une personne déjà inscrite peut recevoir une invitation et choisir de l'accepter ou de la refuser depuis son centre de notifications ; les groupes restent partagés immédiatement. Le lot collaboratif ajoute ensuite demandes de modification, commentaires et mentions sans élargir les droits. Tous les parcours conservent une frontière stricte entre la définition partagée et les tenants ou connexions Oracle, qui restent personnels au lecteur.

### Modèle livré pour les utilisateurs

```text
query_user_shares
- id
- query_id
- shared_by_user_id
- user_id
- permission: view | execute | clone | manage
- status: pending | accepted | declined | cancelled | revoked
- expires_at nullable
- respond_by nullable
- accepted_at nullable
- declined_at nullable
- cancelled_at nullable
- revoked_at nullable
- timestamps

Index de résolution et de cycle de vie :
(query_id, user_id, status)
(user_id, status, respond_by)
(query_id, status, respond_by)
```

La même ligne porte l'invitation puis, après consentement, le grant : `pending` ne participe à aucun scope d'accès ; `accepted` l'active sans perdre son origine. Un refus ou une annulation clôt la ligne en `declined` ou `cancelled`, tandis qu'une révocation ultérieure d'un accès accepté produit `revoked`. L'expiration de l'invitation est calculée lorsque `respond_by` ou `expires_at` est dépassé et n'ajoute donc pas de statut persistant. Chaque nouveau cycle crée une nouvelle ligne afin de préserver l'historique ; le verrou de la requête sérialise la création et empêche un grant actif et une invitation valide concurrents pour le même destinataire. Le partage de groupe applique le même principe historique dans une table dédiée, sans invitation ni relation polymorphique.

La route de partage direct immédiat est conservée pour la compatibilité applicative, mais le parcours utilisateur « Personne » crée une invitation différée. Les candidats sont exclusivement des comptes déjà inscrits : ce lot ne crée ni invitation par adresse libre, ni jeton public, ni e-mail d'invitation.

### Notifications internes implémentées dans le troisième lot

```text
notifications
- id UUID
- type
- notifiable_type
- notifiable_id
- data: share_id, query_id, invited_by_user_id uniquement
- read_at nullable
- timestamps
```

La notification Laravel utilise uniquement le canal `database` et est écrite de manière synchrone dans la transaction qui crée l'invitation. Le navigateur ne reçoit pas de nom ou d'adresse stockés dans `notifications.data` : le contrôleur résout en lot la requête, l'émetteur et l'état courant depuis la ligne de partage autorisée. Le centre `/notifications` est paginé, accepte les filtres `all` et `unread`, autorise la lecture unitaire ou globale et expose un compteur non lu borné dans les props Inertia communes. Une réponse marque la notification correspondante comme lue.

L'émetteur reçoit maintenant une notification interne idempotente lors de l'acceptation ou du refus réel ; un rejeu de la même réponse ne crée aucun doublon. Les e-mails, Teams, Slack, webhooks et autres canaux externes restent futurs et devront être envoyés après commit via la queue.

`queries.deleted_at` transforme la suppression fonctionnelle en archivage. Le propriétaire est le seul à pouvoir l'effectuer ; la requête est verrouillée, tous ses grants actifs sont révoqués, toutes ses invitations encore valides sont annulées et ces mutations sont auditées avant l'archivage dans la même transaction. Les lignes directes, les lignes de groupe et les exécutions ne sont donc plus effacées par cascade. Une éventuelle restauration technique ne réactive ni grant ni invitation ; une future purge physique devra suivre une politique de rétention explicite.

### Groupes et équipes

```text
groups
- id
- owner_id
- name
- description
- timestamps
- deleted_at nullable

group_user
- group_id
- user_id
- role: owner | manager | member
- timestamps

Contrainte d'appartenance :
unique(group_id, user_id)

query_group_shares
- id
- query_id
- group_id
- group_name: snapshot du libellé au moment du cycle
- shared_by_user_id nullable
- permission: view | execute | clone
- status: accepted | revoked
- expires_at nullable
- accepted_at nullable
- revoked_at nullable
- timestamps

Index de résolution et de cycle de vie :
(query_id, group_id, status)
(group_id, status, expires_at)
(query_id, status)
(shared_by_user_id, status)
```

Le créateur devient propriétaire et possède aussi une appartenance `owner`. Le propriétaire peut renommer, décrire et archiver le groupe, nommer ou rétrograder un gestionnaire et gérer tous les membres. Un `manager` peut ajouter ou retirer un membre ordinaire, mais ne peut ni nommer un autre gestionnaire, ni modifier un rôle, ni retirer le propriétaire, un autre gestionnaire ou sa propre appartenance. Un `member` consulte simplement le groupe et bénéficie de ses grants actifs.

Les droits hérités sont calculés à partir des appartenances courantes. Le retrait d'un membre ferme donc immédiatement son accès par ce groupe, alors que le grant reste actif pour les autres membres. L'archivage est une suppression logique : il révoque d'abord tous les grants actifs du groupe dans la même transaction, conserve les lignes de partage pour l'historique et s'appuie sur `group_name` pour préserver le libellé propre à chaque cycle, même après un renommage.

Comme pour une personne, chaque révocation ou expiration clôt un cycle et un repartage crée une nouvelle ligne. Il n'existe pas de contrainte unique permanente qui obligerait à écraser l'historique.

### Permissions livrées et cumulatives

- `view` : consulter les informations ;
- `execute` : exécuter la définition avec l'une des connexions actives appartenant au lecteur ;
- `clone` : créer une copie personnelle ;
- `manage` : gérer le partage, pour le propriétaire ou un délégataire nominatif explicite.

Chaque niveau inclut les précédents : `execute` inclut `view`, `clone` inclut `execute` et `view`, et `manage` inclut les trois. Le droit effectif est le plus fort parmi la propriété, un grant direct, les groupes courants et la portée `organization`. Un grant de groupe est plafonné à `view`, `execute` ou `clone` : `manage` n'est jamais hérité d'un groupe et reste un grant nominatif. Le rôle `manager` d'un groupe autorise la gestion encadrée de ses membres, mais ne confère pas à lui seul la gestion du partage d'une requête.

Même avec `manage`, la modification directe de la définition reste réservée à son propriétaire. Seul le propriétaire peut attribuer `manage` ou changer `access_level`. Un gestionnaire délégué peut administrer les grants `view` à `clone`, directs ou de groupe, sauf son propre grant direct, et ne peut leur donner une durée supérieure à celle de sa propre délégation. Le parcours livré « Demander une modification » protège cette gouvernance sans élargir le droit : tout lecteur peut proposer et commenter, mais seul le propriétaire décide de l'état de la demande.

### Parcours « Gérer le partage » livré

1. Le propriétaire, ou un délégataire nominatif disposant de `manage`, ouvre « Gérer le partage ».
2. Il choisit le type de destinataire « Personne » ou « Groupe », puis recherche un candidat dans le sélecteur serveur paginé correspondant.
3. Il choisit une permission et éventuellement une date d'expiration ; le groupe ne propose jamais `manage`.
4. Pour un groupe, le grant est accepté immédiatement. Pour une personne, l'émetteur fixe aussi une date limite de réponse, bornée par défaut à sept jours et jamais postérieure à l'expiration proposée ; une notification interne est créée sans ouvrir la requête.
5. Le destinataire ouvre `/notifications`, puis accepte ou refuse. Le serveur limite la sélection à ses propres invitations en `404`, verrouille la requête puis la ligne, vérifie qu'elle est encore répondable et revalide l'autorité actuelle de l'émetteur.
6. Une acceptation fait passer la même ligne à `accepted`. Si la requête était encore `private`, elle devient `restricted` dans cette transaction ; elle apparaît alors seulement dans « Partagées avec moi ». Un refus passe la ligne à `declined` sans avoir jamais accordé de droit.
7. L'émetteur peut annuler une invitation encore en attente depuis « Gérer le partage ». Il peut ensuite modifier ou révoquer un grant accepté dans les limites de sa délégation ; son propre grant direct et les autres grants `manage` restent sous le contrôle du propriétaire.
8. Les invitations en attente, les grants actifs et les cycles refusés, annulés, révoqués ou expirés sont présentés séparément sans écraser l'historique ; les groupes conservent leur snapshot de nom.

La page `/groups` fournit en complément le CRUD des groupes accessibles, la liste paginée des membres et candidats, ainsi que les actions autorisées par le rôle courant.

### Sécurité et performance

- appliquer les droits dans la Policy, jamais uniquement dans React ;
- utiliser les mêmes scopes et Policies pour la bibliothèque, le dashboard, le détail, l'exécution et le clonage ;
- indexer la résolution des grants par `(user_id, status, expires_at)` et `(query_id, status)`, ainsi que les invitations par destinataire ou requête avec `status` et `respond_by` ;
- indexer les grants de groupe par groupe, requête, statut et expiration, et borner leur résolution aux groupes courants du lecteur ;
- ne charger ni toute la liste des utilisateurs ni toute la liste des groupes dans le navigateur ;
- considérer l'annuaire actuel comme un annuaire d'organisation unique ; avant toute architecture multi-organisation, borner explicitement les candidats et les adresses visibles au périmètre de l'organisation ;
- masquer en `404` les sélections et grants imbriqués hors du contexte autorisé, puis appliquer les Policies aux mutations de groupe et de membres ;
- borner en `404` chaque réponse à `query_user_shares.user_id = utilisateur courant` et chaque lecture de notification à la relation `user.notifications()` ;
- journaliser création, changement de permission, révocation, archivage de groupe ou de requête, changements de membres et changement de niveau d'accès ;
- interdire qu'un destinataire repartage sans permission `manage` ;
- réserver au propriétaire le changement de portée globale et l'attribution de `manage` ;
- interdire `manage` sur tout grant de groupe et distinguer ce droit du rôle `manager` du groupe ;
- plafonner chaque expiration accordée par un gestionnaire à l'expiration de sa propre délégation ;
- ne jamais faire participer `pending`, `declined` ou `cancelled` aux scopes d'accès, aux Policies ou à « Partagées avec moi » ;
- revalider au moment de l'acceptation le grant `manage` de l'émetteur délégué et annuler l'invitation si cette autorité a disparu ou ne couvre plus la durée proposée ;
- conserver uniquement les identifiants nécessaires dans la notification interne et résoudre les libellés depuis les relations autorisées ;
- créer un nouveau cycle lors d'un repartage afin de ne pas écraser l'historique révoqué ou expiré ;
- révoquer atomiquement les grants de groupe lors de l'archivage et les grants directs plus ceux de groupe lors du passage à `private` ;
- révoquer atomiquement tous les grants actifs avant l'archivage logique d'une requête, sans réactiver ces grants lors d'une restauration technique ;
- annuler atomiquement les invitations encore valides lors du retour à `private` ou de l'archivage logique, sans les réactiver lors d'une restauration technique ;
- recalculer l'accès à partir des appartenances courantes afin qu'un retrait de membre soit effectif immédiatement ;
- autoriser séparément l'accès à la définition puis la connexion choisie par le lecteur ;
- ne jamais transmettre, réutiliser ou révéler le tenant et les credentials du propriétaire lors du partage ;
- résoudre toute exécution partagée avec un tenant et une connexion active appartenant au lecteur.

Avant une montée en charge publique, ajouter des quotas et un throttling dédiés à la création de groupes, aux mutations de membres et aux cycles de partage. La suppression physique future d'une requête, ou la suppression du compte qui la possède, devra également suivre une politique explicite de rétention, d'anonymisation ou de purge puisqu'elle peut déclencher les cascades de base de données que l'archivage fonctionnel évite désormais.

Le partage direct, le partage de groupe, les invitations différées, les réponses vers l'émetteur, les demandes de modification et le centre de notifications interne sont implémentés avec leurs expirations, révocations, historiques, audits et règles d'isolation. Les commentaires immuables et mentions structurées restent soumis à l'accès courant. Les canaux externes sont reportés aux étapes 9 et 12 ; la migration `167000` et la validation PHP finale doivent encore être exécutées localement.

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

- workflow brouillon, revue, publication, certification post-publication et archivage ;
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
- centre de notifications interne — parcours d'invitations implémenté ; extension aux réponses vers l'émetteur, abonnements et autres événements encore à réaliser ;
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
  ├── modèle source nullable
  ├── versions
  ├── executions
  ├── change requests
  ├── user/group shares
  └── audit events

query templates
  ├── définition système immuable
  ├── paramètres typés et liaisons autorisées
  ├── catégorie et traductions FR/EN/ES
  ├── métriques d'aperçu et d'exécution
  ├── politiques d'audit par paramètre
  └── copies personnelles traçables

platform
  ├── identity providers
  ├── politiques réseau et stratégies d'authentification supportées
  ├── roles et permissions
  ├── notifications internes en base et compteur non lu
  ├── jobs et canaux externes futurs
  └── audit global
```

## 24. Feuille de route étendue

Cette extension est conservée pour retracer les décisions d'architecture. Sa numérotation n'est plus prescriptive ; la section 29 constitue l'ordre de réalisation officiel.

### Phase 3 — Tenants personnels, onboarding et gouvernance renforcée

1. Ajouter rôles, permissions et super-administrateur.
2. Migrer les tenants globaux vers des propriétaires explicites et des `auth_connections`.
3. Livrer l'onboarding avec première connexion Basic vérifiée.
4. Protéger chaque tenant par sa Policy de propriété et supprimer le fallback de configuration globale.
5. Créer la console d'administration et l'audit sans exposer les secrets.
6. Créer et verrouiller les templates officiels.
7. Ajouter historique, comparaison et restauration.

### Phase 4 — Collaboration ciblée

1. [x] Remplacer `private/shared` par `private/restricted/organization`.
2. [x] Livrer le partage ciblé direct avec un utilisateur, ses permissions cumulatives et son audit.
3. [x] Ajouter « Partagées avec moi » et « Gérer le partage » avec historique, expiration et révocation.
4. [x] Créer les groupes et le partage d'équipe avec rôles, cycle de vie, historique et retrait immédiat des accès hérités.
5. [x] Archiver logiquement les requêtes supprimées en conservant et clôturant leurs cycles de partage.
6. [x] Créer les demandes de modification avec commentaires immuables, mentions structurées et transitions gouvernées — implémentées ; migration locale et validation PHP finale à relancer.
7. [x] Ajouter les invitations utilisateur avec acceptation différée et le centre de notifications interne — livrés, migrés et validés ; les réponses internes idempotentes vers l'émetteur sont également implémentées. Les canaux externes restent futurs.

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
- masquage des données et restrictions d'export ;
- refus de tout SQL de mutation, de toute requête multiple et de tout identifiant hors catalogue ;
- conversion reproductible du sous-ensemble SQL supporté vers un plan d'appels API ;
- comparaison des résultats traduits avec des jeux de référence couvrant filtres, jointures, agrégations, doublons, ordre et valeurs nulles ;
- diagnostic localisé des fragments SQL partiellement ou non pris en charge ;
- proposition d'une alternative actionnable lorsque l'équivalence complète est impossible ;
- isolation du traducteur SQL par utilisateur, tenant, connexion et droits de colonne ;
- respect des limites de lignes, d'appels, de durée et de mémoire des transformations locales.

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
- query_template_id
- locale
- name
- description
- usage_instructions nullable
- parameter_labels JSON ou table enfant dédiée
- parameter_descriptions JSON ou table enfant dédiée
- parameter_options JSON ou table enfant dédiée

semantic_resource_translations
- semantic_resource_id
- locale
- business_name
- description
- examples nullable
```

Contrainte unique recommandée pour chaque table : `(entité_id, locale)`.

État au 18 juillet 2026 : `query_template_translations` est livré pour le nom, la description, les libellés, les aides et les options des paramètres, avec unicité modèle/locale et repli `locale active -> fr -> contenu source`. `usage_instructions` reste une extension de la future gouvernance éditoriale.

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

### SQL standard vers appels API Oracle — évolution future

L'utilisateur pourra saisir une requête SQL standard afin d'exprimer un besoin connu sans devoir identifier lui-même les endpoints Oracle correspondants. La plateforme n'exécutera jamais ce SQL directement contre la base de données Oracle, la base applicative ou une connexion arbitraire. Elle analysera sa structure, la confrontera à la couche sémantique autorisée, puis tentera de produire un plan d'appels API en lecture seule donnant un résultat équivalent.

Cette évolution ne doit pas être confondue avec l'onglet SQL actuel du query builder. Celui-ci génère un SQL BI Publisher à partir d'une requête déjà configurée ; il ne parse pas un SQL fourni par l'utilisateur et ne construit aucun plan d'API.

Le parcours cible est le suivant :

1. parser le SQL en arbre syntaxique avec un parseur dédié, sans interprétation par expressions régulières ni exécution préalable ;
2. refuser avant toute résolution les instructions autres que la lecture, les requêtes multiples et les constructions ambiguës ;
3. résoudre tables, alias, colonnes, relations et fonctions à partir du catalogue sémantique Oracle versionné ;
4. construire un plan explicite d'appels API : ressources, projections, filtres, tris, pagination, jointures autorisées et éventuelles transformations locales bornées ;
5. classer la conversion `exacte`, `partielle` ou `impossible`, avec un niveau de confiance et les raisons détaillées ;
6. afficher le SQL reçu, le plan produit, les hypothèses et les limites avant l'exécution ;
7. exécuter uniquement après validation, avec les droits, le tenant, la connexion, les quotas et les limites de l'utilisateur courant ;
8. conserver dans l'audit le SQL normalisé ou son empreinte selon sa sensibilité, la version du traducteur, la version du catalogue sémantique, le plan d'API et le statut d'équivalence.

Le premier périmètre doit se limiter à un sous-ensemble déterministe de `SELECT` ANSI :

- sélection et alias de colonnes connues ;
- `WHERE` avec comparaisons, listes, intervalles et opérateurs booléens pris en charge par les API ciblées ;
- `ORDER BY`, pagination et limites ;
- jointures déclarées dans la couche sémantique ;
- agrégations simples uniquement lorsqu'une API Oracle ou une transformation locale bornée permet de préserver la sémantique ;
- paramètres nommés validés séparément des identifiants SQL.

Sont refusés ou signalés comme non convertibles tant qu'une équivalence démontrable n'existe pas :

- `INSERT`, `UPDATE`, `DELETE`, `MERGE`, DDL, transactions et blocs procéduraux ;
- accès à une table, colonne ou relation absente du catalogue autorisé ;
- SQL spécifique à un moteur sans correspondance connue ;
- CTE récursives, sous-requêtes corrélées, fonctions analytiques ou fenêtres non supportées ;
- jointures arbitraires, agrégations non bornées et opérations nécessitant de charger un volume excessif côté application ;
- toute construction dont la traduction modifierait silencieusement les filtres, la cardinalité, les doublons, l'ordre ou la gestion des valeurs nulles.

Une conversion partielle ne doit jamais être présentée comme équivalente. Le diagnostic indique précisément, pour chaque fragment SQL, s'il est converti, approximé ou non pris en charge, ainsi que son impact attendu sur le résultat. Les données ne sont exécutées que si le niveau d'équivalence annoncé respecte le seuil choisi par l'utilisateur ou la politique de gouvernance.

Lorsque la conversion complète est impossible, la plateforme propose une ou plusieurs alternatives actionnables :

- une réécriture SQL compatible avec le sous-ensemble pris en charge ;
- un template de requête API à compléter ou à faire valider ;
- plusieurs appels API avec une transformation locale bornée, clairement identifiée comme telle ;
- un rapport OTBI ou BI Publisher lorsque ce canal est configuré, autorisé et plus fidèle au besoin ;
- une demande d'ajout de mapping dans la couche sémantique, avec les tables, colonnes, fonctions ou relations manquantes déjà extraites.

L'interface doit fournir côte à côte le SQL, le diagnostic de compatibilité et le plan API. Elle distingue visuellement l'aperçu du plan, l'exécution et le résultat, et permet à l'utilisateur de corriger le SQL sans perdre les paramètres reconnus. Aucun nom de ressource, filtre ou relation généré par un modèle ne contourne les listes blanches du backend.

Les critères d'acceptation minimaux sont :

- aucune instruction de mutation ne peut atteindre Oracle ou la base applicative ;
- une requête totalement supportée produit un plan reproductible et des résultats comparés à un jeu de référence ;
- toute perte d'équivalence est annoncée avant l'exécution et localisée dans le SQL ;
- les éléments non pris en charge donnent lieu à une alternative concrète, jamais à un échec opaque ;
- deux utilisateurs traduisant le même SQL restent isolés par leurs droits, tenants, connexions et champs autorisés ;
- les limites de lignes, d'appels, de durée et de transformations locales sont appliquées avant l'exécution ;
- le SQL, le plan et les résultats sensibles ne sont ni journalisés en clair ni transmis à un modèle externe sans politique explicite.

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

**Partiellement livré le 18 juillet 2026 pour les modèles prédéfinis.** Les types nombre, entier, texte, date, liste et booléen sont pris en charge avec valeurs par défaut, obligation, bornes et options autorisées. Les liaisons de filtre et de limite sont construites côté serveur à partir de définitions contrôlées. Leur présentation officielle est disponible en FR/EN/ES et chaque valeur possède une politique d'audit explicite ; les valeurs techniques des listes ne sont jamais traduites.

La suite ajoutera les paramètres métier comme l'unité opérationnelle, le fournisseur, la devise et les listes de valeurs provenant d'Oracle, avec cache prudent et isolation par tenant.

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

Cette feuille de route remplace l'ordre indicatif des sections précédentes. Elle définit l'ordre des priorités et le SSO reste volontairement placé en dernier. Une fondation peut être livrée de manière anticipée lorsqu'un besoin utilisateur direct l'exige, mais cela ne clôt ni les étapes intermédiaires ni l'étape concernée ; le chantier suivant revient ensuite à la première priorité incomplète et actionnable.

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

**Livrée et validée le 17 juillet 2026.**

- catégories et tags traduisibles ;
- favoris et épinglage ;
- vues enregistrées ;
- statistiques d'usage ;
- dashboard optimisé.

### Étape 5 — Partage ciblé et collaboration

**Implémentation interne terminée le 18 juillet 2026. Migration locale et validation PHP finale à relancer. Les canaux externes relèvent des étapes 9 et 12.**

- [x] niveaux `private`, `restricted`, `organization` ;
- [x] partage direct avec des utilisateurs et gestion paginée des destinataires ;
- [x] permissions cumulatives `view`, `execute`, `clone`, `manage` ;
- [x] expiration, révocation, historique et audit ;
- [x] accès cohérent entre bibliothèque, dashboard, détail, exécution et clonage ;
- [x] exécution exclusivement avec une connexion Oracle appartenant au lecteur ;
- [x] partage avec des groupes, gestion `/groups`, rôles `owner/manager/member`, archivage logique et retrait immédiat des accès hérités ;
- [x] permissions de groupe plafonnées à `view/execute/clone`, cycles d'expiration et révocation conservés avec snapshot du nom ;
- [x] archivage logique des requêtes, révocation atomique et audit des grants actifs, conservation des cycles et des exécutions ;
- [x] invitations nominatives `pending` sans accès, acceptation/refus différés, revalidation du délégataire, annulation lors du retour à `private` ou de l'archivage, et centre interne paginé avec états lus/non lus — livrés et validés ;
- [x] notification interne idempotente de la réponse à l'émetteur ;
- [x] demandes de modification, commentaires immuables et mentions structurées ;
- [x] clôture atomique des demandes ouvertes lors de l'archivage et masquage du contexte après perte d'accès ;
- [ ] e-mails via la queue — étape 9 ; connecteurs externes — étape 12.

### Étape 6 — Gouvernance et templates officiels

**En cours. Lots 6A/6B implémentés le 18 juillet 2026 ; validations PHP/Node et migrations locales `168000`/`169000` à relancer. La délégation éditoriale spécialisée et l'édition Oracle contrôlée restent à livrer.**

- [x] historique immuable des versions officielles et provenance exacte des clones/exécutions ;
- [x] comparaison exacte et restauration non destructive sous forme d'un nouveau brouillon ;
- [x] templates verrouillés — bibliothèque, paramètres, contenu FR/EN/ES, aperçu, exécution exacte mesurée, audit filtré et clone privé livrés ;
- [x] workflow `draft -> review -> published`, projection atomique et archivage ;
- [x] certification post-publication liée à la version et à son empreinte, badge public effectif et révocations historisées ;
- [x] propriétaires métier et dates de révision ;
- [ ] rôles éditoriaux spécialisés, responsable technique et édition contrôlée de la définition Oracle.

### Étape 7 — Couche sémantique Oracle

- catalogue de métadonnées synchronisé ;
- relations, synonymes et glossaire trilingue ;
- classification des données ;
- détection de changements de schéma ;
- lignée entre requêtes et ressources ;
- mappings déterministes entre tables/colonnes SQL autorisées et ressources/champs API, prérequis du futur traducteur SQL.

### Étape 8 — Fiabilité et tests de données

- assertions configurables ;
- validation automatique avant publication ;
- surveillance des requêtes certifiées ;
- score de santé ;
- détection des requêtes lentes ou cassées ;
- jeux de référence et comparaisons d'équivalence couvrant filtres, jointures, doublons, ordre, agrégations et valeurs nulles pour le futur traducteur SQL.

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
- analyse de SQL standard et traduction déterministe en plans d'appels API en lecture seule ;
- diagnostic `exact`, `partiel` ou `impossible`, avec fragments non pris en charge et alternatives ;
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
