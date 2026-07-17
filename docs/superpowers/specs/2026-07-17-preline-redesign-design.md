# Refonte visuelle épurée façon Preline (sidebar conservée)

**Date :** 2026-07-17
**Statut :** validé (portée : toute l'app ; dashboard enrichi de données réelles ; approche tokens + retouches)

## Objectif

Aligner toute l'app sur l'esthétique Preline (référence : screenshots « All customers »
et « Dashboard ») : fond blanc, primaire noir, gris neutres, bordures fines,
beaucoup d'air. Unique différence assumée : la navigation reste en **sidebar**
(pas de menu horizontal), adaptée au concept requêtes Oracle.

## Décisions validées

- **Portée** : toute l'app (coquille, dashboard, requêtes index/show, builder,
  tenants, settings/auth par héritage des tokens).
- **Dashboard** : enrichi uniquement avec des données réelles — sparkline
  « requêtes créées par semaine » (8 semaines), répartition par domaine Oracle
  (via le `resource_key` des requêtes et le catalogue), état des tenants.
  Aucune donnée inventée ; pas de tracking d'exécutions pour l'instant.
- **Approche** : palette via les tokens globaux `app.css` (effet immédiat
  partout), puis composants partagés et retouches page par page.

## Thème (tokens)

- Light : fond blanc pur, texte quasi-noir, `--primary` noir (boutons pleins),
  gris neutres sans teinte indigo, `--border` gris clair, `--radius: 0.5rem`,
  sidebar blanche avec bordure fine.
- Dark conservé : équivalents gris neutres foncés, primaire clair.

## Coquille

Sidebar épurée : logo compact, libellé de section uppercase 11px gris,
items avec icône fine et état actif discret (fond gris léger + texte noir),
utilisateur en bas. Variante « sidebar » simple avec bordure (plus d'inset
flottant). Fil d'ariane conservé sur une ligne fine.

## Composants partagés

| Composant | Rôle |
|---|---|
| `StatCard` | libellé uppercase xs, valeur 2xl, badge de tendance (réel uniquement), sparkline SVG maison optionnelle |
| `PageHeader` | titre + description + zone d'actions à droite |
| `EntityChip` | pastille bordée icône + libellé (tenants, domaines, modes) |
| `ResultsTable` | en-têtes gris clair uppercase xs, lignes aérées, hover doux |

## Pages

- **Dashboard** : 4 StatCards (accessibles + sparkline, miennes, tenants
  actifs, partagées), panneau « Répartition par domaine » en barres segmentées,
  panneau tenants avec statut, table « Requêtes récentes » style Preline.
  `DashboardController` fournit `queriesPerWeek` et `domainBreakdown` + tests.
- **Requêtes index** : table pleine largeur (nom, tenant chip, mode,
  visibilité, propriétaire) avec recherche client.
- **Show / Tenants** : PageHeader + chips + espacements.
- **Builder / Auth / Settings** : héritent des tokens, retouches mineures.

## Tests & vérifications

Tests Pest sur les nouvelles séries du dashboard ; suite complète, Pint,
Prettier (fichiers touchés), ESLint, tsc, build Vite.
