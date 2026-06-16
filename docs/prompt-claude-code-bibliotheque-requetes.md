# Brief — Fonctionnalité « Bibliothèque de requêtes » (v1)

## Contexte
Application web interne de visualisation de données Oracle Fusion. But de l'app : permettre à des utilisateurs d'**enregistrer, exécuter et partager** des requêtes vers Oracle Fusion, et d'afficher les résultats dans un tableau.

Stack : **Laravel + Inertia.js + React**. Inspecte le projet pour confirmer la version exacte, le bundler (Vite), JS ou TS, le scaffolding d'auth, et les conventions existantes — puis suis-les.

## Ce qui existe déjà (à RÉUTILISER, surtout ne pas recréer)
Un service `App\Services\FusionClient` qui gère la connexion à Oracle Fusion en **Basic Auth** (compte de service). Il est déjà enregistré en singleton et injectable. Son API :
- `get(string $path, array $query = []): array` — GET générique, renvoie le JSON décodé.
- `list(string $path, array $query = []): array` — renvoie seulement les lignes (`items`).
- `testConnection(): bool`.

**Toute** communication avec Fusion DOIT passer par ce service — c'est le point unique d'authentification (on y branchera OAuth/SSO plus tard). Config dans `config/fusion.php` + variables `FUSION_BASE_URL`, `FUSION_USERNAME`, `FUSION_PASSWORD` dans `.env`.

Exemples de chemins de ressources Fusion :
- HCM : `/hcmRestApi/resources/11.13.18.05/workers`
- ERP / Finance : `/fscmRestApi/resources/11.13.18.05/...`

## Objectif de cette étape
Construire la fonctionnalité « Bibliothèque de requêtes » : CRUD de requêtes enregistrées + exécution via `FusionClient` + affichage des résultats. **Garder ça simple et propre** ; on enrichira ensuite, étape par étape.

## Modèle de données
Migration + modèle Eloquent `Query` :
- `user_id` (FK vers `users` — le propriétaire)
- `name` (string)
- `description` (text, nullable)
- `resource_path` (string) — le chemin REST Fusion
- `parameters` (json, cast en array) — paramètres de la requête (`limit`, `q`, `fields`, `offset`, etc.)
- `visibility` (string : `private` ou `shared`) — `shared` = visible et exécutable par tout utilisateur authentifié
- timestamps

Relation : `Query belongsTo User`.

## Backend
Routes + `QueryController` (Inertia) :
- `GET /queries` — liste (requêtes de l'utilisateur + celles en `shared`)
- `GET /queries/create` — formulaire de création
- `POST /queries` — enregistrement
- `GET /queries/{query}` — détail + exécution
- `POST /queries/{query}/run` — exécute la requête via `FusionClient` et renvoie les résultats
- `PUT /queries/{query}` — mise à jour
- `DELETE /queries/{query}` — suppression

Règles :
- Seul le **propriétaire** peut modifier/supprimer (utilise une Policy Laravel, ou des checks explicites).
- Les requêtes `shared` sont consultables et exécutables par tous les utilisateurs authentifiés.
- L'exécution prend `resource_path` + `parameters` de la requête, appelle `FusionClient`, et renvoie `items` + métadonnées utiles si présentes (`hasMore`, `count`).
- **Capture les erreurs** (`RuntimeException` du client) et renvoie un message propre à l'UI — jamais une page 500 brute.

Validation :
- `resource_path` : chaîne non vide commençant par `/`, restreinte par une **liste blanche de préfixes** (`/hcmRestApi/`, `/fscmRestApi/`) pour empêcher l'appel d'URLs arbitraires.
- `parameters` : tableau optionnel ; valide les clés attendues, ignore le reste.
- `visibility` : `in:private,shared`.

## Frontend (Inertia + React)
- **Page liste** : tableau des requêtes enregistrées (nom, description, visibilité, propriétaire) avec actions (Exécuter ; Modifier/Supprimer pour les siennes).
- **Formulaire création/édition** : nom, description, `resource_path`, paramètres (au minimum : `limit`, un champ texte libre pour `q`, optionnellement `fields`), bascule de visibilité.
- **Affichage des résultats** : un **tableau générique** qui déduit ses colonnes à partir des clés des objets renvoyés (la forme du JSON Fusion varie selon la ressource). Gère les états : chargement, résultats vides, erreur.
- Ajoute un lien de navigation vers `/queries` pour que la fonctionnalité soit accessible.
- Style simple et propre, en suivant la lib/les conventions UI déjà présentes (Tailwind si présent). Réutilise les composants du starter existant.

## Contraintes
- **Lecture seule** vers Fusion (uniquement des GET) pour le moment.
- Tout passe par `FusionClient` — ne crée aucun autre client HTTP vers Fusion.
- Garde les composants petits et réutilisables.
- Conçois le stockage (`resource_path` + `parameters` en JSON) de façon à ce qu'une future fonctionnalité puisse **générer ces valeurs automatiquement** sans refonte.

## Hors scope (NE PAS faire maintenant)
- SSO / identité par utilisateur vers Fusion. Le compte de service suffit pour cette étape ; le filtrage par utilisateur viendra plus tard — **documente cette limite** (tous les utilisateurs voient les mêmes données).
- La traduction **langage naturel → requête** par un LLM (étape ultérieure ; mais le modèle de données doit pouvoir l'accueillir).
- Intégration OTBI / BI Publisher.
- Pagination avancée — un simple `limit` suffit ; note la pagination comme amélioration future.

## Démarche attendue
1. **Inspecte d'abord** le projet : version de Laravel, setup Inertia/React (Vite, JS/TS), scaffolding d'auth existant, driver de BD, routes et composants déjà présents. Suis les conventions en place.
2. **Prérequis** : si l'app n'a pas encore d'authentification utilisateur, mets en place l'auth de base de Laravel (Breeze ou équivalent) en premier, car chaque requête a un propriétaire.
3. Implémente migration + modèle, puis le backend, puis le frontend.
4. Lance la migration et vérifie qu'elle passe.
5. Teste le flux de bout en bout.

## Critères d'acceptation
- Je peux créer une requête (ex. nom « Liste des employés », path `/hcmRestApi/resources/11.13.18.05/workers`, params `{ "limit": 25 }`) et l'enregistrer.
- Elle apparaît dans la liste.
- Je peux l'exécuter et voir les données dans un tableau.
- En la passant en `shared`, un autre utilisateur la voit et peut l'exécuter.
- Je peux modifier/supprimer mes propres requêtes, mais pas celles des autres.
- Une erreur renvoyée par Fusion s'affiche proprement dans l'UI (pas de page blanche / 500).
