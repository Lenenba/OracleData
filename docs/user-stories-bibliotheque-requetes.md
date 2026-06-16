# User Stories — Fonctionnalité « Bibliothèque de requêtes » (v1)

> Document de pilotage. On exécute les stories **dans l'ordre** (Story 0 → 7). Chaque story est livrable et testable indépendamment.
> Brief source : [`prompt-claude-code-bibliotheque-requetes.md`](./prompt-claude-code-bibliotheque-requetes.md)

## Contexte technique (état réel du projet)

Inspection effectuée le 2026-06-16. À suivre comme conventions de référence :

- **Stack** : Laravel 13 (PHP 8.4), Inertia v3, **React 19 + TypeScript**, Tailwind v4, Vite, Wayfinder (routes typées), Pest 4.
- **Starter kit** : `laravel/react-starter-kit` avec UI type shadcn (Radix + `class-variance-authority`). Composants UI dispo dans `resources/js/components/ui/` : `button`, `card`, `input`, `select`, `badge`, `dialog`, `skeleton`, `spinner`, `alert`, `label`, etc. **Pas de composant `table`** → à créer pour l'affichage des résultats.
- **Base de données** : SQLite par défaut (`config/database.php`).
- **Auth** : ✅ **déjà en place** via Laravel Fortify (login, register, vérification e-mail, 2FA, passkeys, settings profil/sécurité). Le prérequis « mettre en place l'auth » du brief est donc **déjà satisfait** — rien à faire de ce côté.
- **Conventions backend** :
  - Contrôleurs Inertia : `Inertia::render('page', [...])` (voir `app/Http/Controllers/Settings/ProfileController.php`).
  - Validation via **FormRequest** dédiés (`app/Http/Requests/...`).
  - Feedback UI : `Inertia::flash('toast', ['type' => 'success', 'message' => __('...')])` → affiché côté front avec `sonner`.
  - Redirections via `to_route()` / routes nommées.
- **Conventions front** : pages dans `resources/js/pages/`, layout principal `resources/js/layouts/app-layout.tsx`, navigation latérale via `resources/js/components/nav-main.tsx` / `app-sidebar.tsx`.
- **Routes** : `routes/web.php` (groupe `middleware(['auth', 'verified'])`).
- **Outils qualité** (à faire passer avant de clôturer chaque story) :
  - `vendor/bin/pint --dirty --format agent` (formatage PHP)
  - `npm run types:check` (TypeScript), `npm run lint:check`, `npm run format:check`
  - `php artisan test --compact` (Pest)

> ⚠️ **Écart avec le brief** : le service `App\Services\FusionClient` et `config/fusion.php` sont décrits comme « existants » mais **n'existent pas** dans le projet. Ils sont donc construits en **Story 0**.

## Multi-tenant Fusion (extension du brief)

Besoin : un même utilisateur travaille pour plusieurs clients (tenant X, tenant Y…), chacun ayant son **propre environnement Oracle Fusion** (URL + compte de service distincts). Il doit pouvoir **choisir le tenant cible au moment d'exécuter** une requête.

Décisions retenues (v1) :

- **Définition des tenants : en config (`.env`)**. `config/fusion.php` expose une liste de tenants nommés, chacun avec `label`, `base_url`, `username`, `password` lus depuis des variables d'environnement dédiées. Aucun secret en base de données.
- **Liaison tenant ↔ requête : à l'exécution.** La requête enregistrée reste **agnostique du tenant** (aucune colonne tenant sur le modèle `Query`) ; le tenant est sélectionné via un menu au moment du `run`. Une même requête est donc réutilisable sur tous les clients.
- **Accès : tous les utilisateurs authentifiés peuvent cibler tous les tenants configurés** (cohérent avec la limite « compte de service unique »). Restriction par utilisateur/rôle = amélioration future.
- **Architecture** : on introduit un `App\Services\FusionManager` (singleton) qui **résout un `FusionClient` par tenant**. `FusionClient` reste simple (une connexion = un tenant) ; le manager gère la résolution, la liste des tenants disponibles et la validation de la clé de tenant (liste blanche implicite = clés configurées).

Conséquences sur les stories : **Story 0** construit le manager + le client ; **Story 4** ajoute le sélecteur de tenant et passe la clé au `run` ; les Stories 1–3, 5–6 sont inchangées côté tenant.

## Stratégie de test (Oracle Fusion)

- **Aucun appel réseau réel dans les tests.** On utilise `Http::fake()` pour simuler Fusion.
- Les mocks reproduisent l'**enveloppe standard Oracle Cloud REST** (réf. doc Oracle : <https://docs.oracle.com/en/cloud/saas/index.html>) :

```json
{
  "items": [
    { "PersonId": 300000001, "PersonNumber": "1001", "DisplayName": "Jean Dupont" },
    { "PersonId": 300000002, "PersonNumber": "1002", "DisplayName": "Marie Martin" }
  ],
  "count": 2,
  "hasMore": true,
  "limit": 25,
  "offset": 0,
  "links": [ { "rel": "self", "href": "..." } ]
}
```

- Les clés des objets de `items` **varient selon la ressource** — c'est exactement pourquoi le tableau de résultats déduit ses colonnes dynamiquement (Story 4).
- **Multi-tenant** : `Http::fake()` peut router par URL (`fake([ 'tenant-x.fa.oraclecloud.com/*' => ..., 'tenant-y.fa.oraclecloud.com/*' => ... ])`) pour vérifier qu'on tape bien le bon environnement selon le tenant choisi.

## Définition de « terminé » (DoD) — commune à toutes les stories

- [ ] Code conforme aux conventions existantes (sibling files).
- [ ] Tests Pest écrits et **verts** (`php artisan test --compact`).
- [ ] Pint, ESLint, Prettier, `tsc` passent sans erreur.
- [ ] Critères d'acceptation de la story validés manuellement.

---

## Story 0 — Connexion multi-tenant à Oracle Fusion (`FusionManager` + `FusionClient`)

**En tant que** développeur de l'application,
**je veux** un service capable de parler à **plusieurs environnements Oracle Fusion** (un par client) en Basic Auth,
**afin que** toute la fonctionnalité s'appuie sur un point d'authentification unique et puisse cibler le bon tenant (extensible OAuth/SSO plus tard).

### Critères d'acceptation
- Un service `App\Services\FusionClient` représentant **la connexion à UN tenant**. API exacte : `get(string $path, array $query = []): array`, `list(string $path, array $query = []): array` (renvoie `items`), `testConnection(): bool`.
- Un service `App\Services\FusionManager` enregistré en **singleton** et injectable, qui résout un `FusionClient` par tenant :
  - `tenant(string $key): FusionClient` — lève une exception claire si la clé est inconnue ;
  - `default(): FusionClient` — tenant par défaut (`config('fusion.default')`) ;
  - `available(): array` — `['client_x' => 'Client X (Prod)', ...]` (clé → libellé) pour alimenter le sélecteur côté UI ;
  - `has(string $key): bool`.
- Toute communication Fusion passe par ces services (aucun autre client HTTP vers Fusion ailleurs).
- Erreurs réseau/HTTP encapsulées en `RuntimeException` avec message exploitable (jamais une 500 brute en amont).
- Lecture seule (GET uniquement) pour le moment.

### Tâches techniques
- `config/fusion.php` :
  ```php
  return [
      'default' => env('FUSION_DEFAULT_TENANT', 'client_x'),
      'tenants' => [
          'client_x' => [
              'label' => 'Client X (Production)',
              'base_url' => env('FUSION_CLIENT_X_BASE_URL'),
              'username' => env('FUSION_CLIENT_X_USERNAME'),
              'password' => env('FUSION_CLIENT_X_PASSWORD'),
          ],
          'client_y' => [
              'label' => 'Client Y',
              'base_url' => env('FUSION_CLIENT_Y_BASE_URL'),
              'username' => env('FUSION_CLIENT_Y_USERNAME'),
              'password' => env('FUSION_CLIENT_Y_PASSWORD'),
          ],
      ],
  ];
  ```
- Variables `.env` + `.env.example` : un trio `FUSION_<TENANT>_BASE_URL` / `_USERNAME` / `_PASSWORD` par tenant, plus `FUSION_DEFAULT_TENANT`.
- `app/Services/FusionClient.php` :
  - constructeur avec property promotion + types explicites (`base_url`, `username`, `password`) ;
  - `get()` : `Http::withBasicAuth(...)->baseUrl(...)->get($path, $query)`, `throw()` → catch en `RuntimeException` ;
  - `list()` : `get()` puis retourne `$response['items'] ?? []` ;
  - `testConnection()` : ping léger, renvoie bool.
- `app/Services/FusionManager.php` : lit `config('fusion.tenants')`, instancie/mémoïse un `FusionClient` par clé, expose `tenant/default/available/has`.
- Enregistrement du **`FusionManager` en singleton** dans `app/Providers/AppServiceProvider.php`.

### Tests (`tests/Feature/Fusion/FusionClientTest.php` + `FusionManagerTest.php`)
- `Http::fake()` (enveloppe standard) → `FusionClient::list()` retourne bien le tableau `items`.
- `get()` propage métadonnées (`hasMore`, `count`).
- Réponse HTTP 401/500 simulée → `RuntimeException` avec message propre.
- `testConnection()` renvoie `true` sur 200, `false` sur échec.
- `FusionManager::tenant('client_x')` et `tenant('client_y')` tapent des `base_url` différentes (vérifié via `Http::fake` routé par URL).
- `FusionManager::tenant('inconnu')` lève une exception.
- `FusionManager::available()` renvoie les clés/libellés configurés.

### Fichiers concernés
`config/fusion.php`, `.env(.example)`, `app/Services/FusionClient.php`, `app/Services/FusionManager.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/Fusion/*`.

---

## Story 1 — Modèle de données des requêtes

**En tant que** utilisateur authentifié,
**je veux** que mes requêtes enregistrées soient persistées avec leur propriétaire,
**afin de** les retrouver, les exécuter et (plus tard) les partager.

### Critères d'acceptation
- Table `queries` avec : `user_id` (FK `users`), `name` (string), `description` (text nullable), `resource_path` (string), `parameters` (json, casté `array`), `visibility` (string `private`|`shared`), timestamps.
- Modèle `Query` avec `belongsTo(User)`, casts (`parameters => array`), `$fillable` approprié.
- Relation inverse `User hasMany Query` (utile pour le filtrage).
- Migration qui passe (`php artisan migrate`).

### Tâches techniques
- `php artisan make:model Query -mf` (modèle + migration + factory).
- Migration : colonnes ci-dessus, `foreignId('user_id')->constrained()->cascadeOnDelete()`.
- Modèle : casts, relations, PHPDoc des propriétés.
- `QueryFactory` avec états utiles : `private()`, `shared()`, valeurs `resource_path`/`parameters` réalistes (ex. `/hcmRestApi/resources/11.13.18.05/workers`, `{ "limit": 25 }`).
- (Optionnel) Seeder de démo.

### Tests (`tests/Unit/QueryTest.php` ou Feature)
- `parameters` est bien casté en array à la lecture.
- Relation `query->user` et `user->queries` fonctionnent.
- La factory produit des modèles valides (`private` / `shared`).

### Conception extensible (important)
- `resource_path` + `parameters` (JSON) stockés tels quels → une future fonctionnalité **LLM (langage naturel → requête)** pourra **générer** ces deux valeurs sans refonte du schéma.
- **Multi-tenant** : le modèle reste **agnostique du tenant** (pas de colonne tenant). Le tenant est choisi à l'exécution (Story 4). Si un jour on veut un tenant par défaut par requête, ce sera une simple colonne `default_tenant` nullable — sans refonte.

---

## Story 2 — Enregistrer une requête (création)

**En tant que** utilisateur authentifié,
**je veux** créer et enregistrer une requête (nom, description, chemin REST, paramètres, visibilité),
**afin de** la réutiliser et l'exécuter ensuite.

### Critères d'acceptation
- `GET /queries/create` affiche le formulaire de création.
- `POST /queries` enregistre la requête avec `user_id = auth()->id()`.
- Validation stricte (voir ci-dessous) ; erreurs renvoyées au formulaire.
- `resource_path` restreint par **liste blanche de préfixes** (`/hcmRestApi/`, `/fscmRestApi/`) — toute autre valeur est rejetée.
- Toast de succès + redirection vers la liste.

### Tâches techniques
- `php artisan make:controller QueryController` (méthodes Inertia : `index`, `create`, `store` pour cette story).
- `app/Http/Requests/StoreQueryRequest.php` :
  - `name` : `required|string|max:255` ;
  - `description` : `nullable|string` ;
  - `resource_path` : `required|string|starts_with:/` + règle liste blanche de préfixes ;
  - `parameters` : `nullable|array` ; sous-clés attendues (`limit` int, `q` string, `fields` string, `offset` int) validées, le reste ignoré ;
  - `visibility` : `required|in:private,shared`.
- Routes dans `routes/web.php` sous `middleware(['auth','verified'])` (idéalement `Route::resource('queries', QueryController::class)`).
- Génération Wayfinder pour les routes typées côté front.

### Tests (`tests/Feature/QueryStoreTest.php`)
- Un utilisateur authentifié crée une requête → persistée avec le bon `user_id`.
- `resource_path` hors liste blanche → erreur de validation, rien en base.
- `visibility` invalide → rejet.
- Invité (non authentifié) → redirigé vers login.

---

## Story 3 — Lister mes requêtes et les requêtes partagées

**En tant que** utilisateur authentifié,
**je veux** voir la liste de mes requêtes et de celles partagées par d'autres,
**afin de** retrouver rapidement ce que je peux exécuter.

### Critères d'acceptation
- `GET /queries` affiche un tableau : nom, description, visibilité, propriétaire.
- Contenu = mes requêtes **+** toutes les requêtes `shared` (des autres).
- Actions par ligne : **Exécuter** (toutes) ; **Modifier/Supprimer** (uniquement les miennes).
- Un lien de navigation vers `/queries` est ajouté dans la barre latérale (`nav-main.tsx`).

### Tâches techniques
- `QueryController@index` : `Query::where('user_id', $userId)->orWhere('visibility','shared')->with('user')->latest()->get()` → passé en prop Inertia (DTO léger / ressource).
- Page `resources/js/pages/queries/index.tsx` : tableau, badges de visibilité (`badge`), boutons d'action conditionnels selon `query.user_id === auth.user.id`.
- Ajout de l'entrée de menu (icône `lucide-react`) vers la route Wayfinder `queries.index`.
- Props d'auth déjà partagées par `HandleInertiaRequests` (vérifier la forme `auth.user`).

### Tests (`tests/Feature/QueryIndexTest.php`)
- L'utilisateur voit ses propres requêtes.
- Il voit les requêtes `shared` d'un autre utilisateur.
- Il **ne voit pas** les requêtes `private` d'un autre.
- Les drapeaux « peut modifier » ne sont vrais que pour ses propres requêtes.

---

## Story 4 — Exécuter une requête et afficher les résultats

**En tant que** utilisateur authentifié,
**je veux** exécuter une requête enregistrée et voir les données dans un tableau,
**afin d'**obtenir les informations Oracle Fusion sans écrire de code.

### Critères d'acceptation
- `GET /queries/{query}` : page de détail + déclencheur d'exécution, **avec un sélecteur de tenant** (libellés issus de `FusionManager::available()`).
- `POST /queries/{query}/run` : reçoit une clé `tenant`, exécute via `FusionManager->tenant($key)->get(resource_path, parameters)`, renvoie `items` + métadonnées (`hasMore`, `count`) si présentes.
- La clé `tenant` est **validée** contre les tenants configurés ; tenant absent/inconnu → erreur propre (pas d'exécution).
- Le tenant par défaut (`FusionManager::default`) est pré-sélectionné.
- Les résultats affichés indiquent **quel tenant** a été interrogé.
- Accès : propriétaire **ou** requête `shared` (sinon 403). Tout tenant configuré est sélectionnable par tout utilisateur authentifié (limite v1).
- **Tableau générique** : colonnes déduites des clés des objets de `items` (gère le cas où les objets ont des formes différentes en faisant l'union des clés).
- États gérés : **chargement** (skeleton/spinner), **résultats vides**, **erreur** (message propre, jamais de page 500/blanche).

### Tâches techniques
- `QueryController@show` (détail) : passe `tenants = FusionManager::available()` et `defaultTenant` en props Inertia.
- `QueryController@run` (exécution) :
  - `RunQueryRequest` valide `tenant` : `required|string` + `Rule::in(array_keys(config('fusion.tenants')))` ;
  - autorisation (propriétaire ou `shared`) ;
  - `try { app(FusionManager::class)->tenant($validated['tenant'])->get(...) } catch (RuntimeException $e) { ... message propre ... }`.
  - Décider du transport des résultats : réponse Inertia (prop) ou `useHttp` (v3) selon ergonomie — privilégier une requête déclenchée par l'utilisateur sans recharger la page.
- Page `resources/js/pages/queries/show.tsx` : sélecteur de tenant (`components/ui/select.tsx`) + bouton Exécuter + zone résultats.
- Composant réutilisable `resources/js/components/results-table.tsx` :
  - props `{ items: Record<string, unknown>[] }` ;
  - calcule l'ensemble des colonnes (union des clés) ;
  - rend valeurs scalaires ; sérialise/condense les valeurs objet/array.
- Composant d'erreur réutilisé (`alert-error.tsx` existant).

### Tests (`tests/Feature/QueryRunTest.php`)
- `Http::fake()` (enveloppe standard) → `run` avec un tenant valide renvoie `items` + `hasMore`/`count`.
- Exécuter sur `client_x` vs `client_y` tape la bonne `base_url` (fake routé par URL).
- `tenant` manquant ou inconnu → erreur de validation, aucune exécution.
- Fusion renvoie une erreur → réponse contient un message d'erreur propre, **pas** de 500.
- Un utilisateur exécute une requête `shared` d'un autre → autorisé.
- Un utilisateur tente d'exécuter une requête `private` d'un autre → 403.

---

## Story 5 — Modifier / supprimer ses requêtes (propriétaire seul)

**En tant que** propriétaire d'une requête,
**je veux** la modifier ou la supprimer,
**afin de** maintenir ma bibliothèque à jour — sans pouvoir toucher à celles des autres.

### Critères d'acceptation
- `GET /queries/{query}/edit` (formulaire pré-rempli), `PUT /queries/{query}`, `DELETE /queries/{query}`.
- **Seul le propriétaire** peut éditer/supprimer (sinon 403) — via **Policy Laravel**.
- Le formulaire d'édition réutilise les champs du formulaire de création.
- Toasts de succès + redirection vers la liste.

### Tâches techniques
- `php artisan make:policy QueryPolicy --model=Query` : `update` et `delete` → `return $user->id === $query->user_id;`. `view` → propriétaire ou `shared`.
- `authorize()` dans le contrôleur (ou `$this->authorize(...)`).
- `app/Http/Requests/UpdateQueryRequest.php` (mêmes règles que `Store`, ou FormRequest partagé).
- Réutiliser le composant de formulaire entre create/edit (`resources/js/pages/queries/edit.tsx` + composant `query-form.tsx` partagé avec Story 2).

### Tests (`tests/Feature/QueryUpdateDeleteTest.php`)
- Le propriétaire met à jour sa requête → modifs persistées.
- Le propriétaire supprime sa requête → retirée de la base.
- Un autre utilisateur tente update/delete → **403**, aucune modification.

---

## Story 6 — Partage des requêtes (`shared`)

**En tant que** utilisateur,
**je veux** basculer une requête en `shared`,
**afin que** les autres utilisateurs authentifiés puissent la voir et l'exécuter.

### Critères d'acceptation
- Bascule de visibilité dans le formulaire (création et édition).
- Passer une requête en `shared` la rend visible **et exécutable** par tout utilisateur authentifié.
- La repasser en `private` la masque aux autres.
- Les autres utilisateurs ne peuvent toujours **pas** la modifier/supprimer (Story 5).

### Tâches techniques
- Géré majoritairement par les stories 2/3/5 (champ `visibility` + filtrage + Policy `view`). Cette story **valide le flux de bout en bout** et ajoute les tests cross-user manquants.
- Vérifier l'UI : indicateur clair `private`/`shared` (badge), libellé explicite sur la bascule.

### Tests (`tests/Feature/QuerySharingTest.php`)
- Utilisateur A passe une requête en `shared` → utilisateur B la voit dans `index` et peut l'exécuter (`run`).
- A la repasse en `private` → B ne la voit plus, `run`/`show` → 403.
- B ne peut jamais éditer/supprimer la requête de A.

---

## Story 7 — Finitions & limites documentées

**En tant que** équipe produit,
**je veux** que les limites connues et le périmètre futur soient documentés,
**afin d'**éviter les malentendus et préparer les étapes suivantes.

### Critères d'acceptation
- Une section « Limites & périmètre » est ajoutée (README de la feature ou ce doc) précisant :
  - **Compte de service unique par tenant** : pas de SSO ni d'identité par utilisateur vers Fusion pour l'instant → **tous les utilisateurs voient les mêmes données** pour un tenant donné (le filtrage par utilisateur viendra plus tard).
  - **Multi-tenant en config** : ajouter un client = ajouter des variables `.env` + redéploiement ; les identifiants ne sont pas gérables via l'UI en v1. Gestion en base + UI d'admin = amélioration future.
  - **Accès tenants non restreint** : tout utilisateur authentifié peut cibler tout tenant configuré ; restriction par utilisateur/rôle = amélioration future.
  - **Lecture seule** (GET) uniquement.
  - **Pagination** : seul `limit` est géré ; pagination avancée = amélioration future.
  - **LLM (langage naturel → requête)** : hors scope, mais le modèle de données (`resource_path` + `parameters` JSON) est prêt à l'accueillir.
  - **OTBI / BI Publisher** : hors scope.
- Revue finale : navigation, états de chargement/vides/erreur cohérents, pas de régression sur les tests.

### Tâches techniques
- Rédiger la section limites (ce fichier ou `docs/`).
- Passe finale qualité : `composer run test` (lint + types + Pest) vert.

---

## Critères d'acceptation globaux (rappel du brief)

- [ ] Créer une requête (nom « Liste des employés », path `/hcmRestApi/resources/11.13.18.05/workers`, params `{ "limit": 25 }`) et l'enregistrer.
- [ ] Elle apparaît dans la liste.
- [ ] Choisir un tenant (client X ou Y) et exécuter la requête → voir les données du bon environnement dans un tableau.
- [ ] La même requête peut être exécutée sur un autre tenant sans la dupliquer.
- [ ] En `shared`, un autre utilisateur la voit et peut l'exécuter (sur le tenant de son choix).
- [ ] Modifier/supprimer ses propres requêtes, mais pas celles des autres.
- [ ] Une erreur Fusion s'affiche proprement (pas de page blanche / 500).

## Ordre d'exécution recommandé

`Story 0` → `1` → `2` → `3` → `4` → `5` → `6` → `7`

Dépendances clés : tout dépend de **Story 0** (FusionClient) et **Story 1** (modèle). Les stories 5 et 6 réutilisent le travail des stories 2–4.
