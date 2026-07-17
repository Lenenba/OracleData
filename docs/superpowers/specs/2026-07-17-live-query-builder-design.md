# Query builder live (remplacement du wizard 3 étapes)

**Date :** 2026-07-17
**Statut :** validé (layout deux colonnes, refresh tout-auto débouncé, approche « nouveau composant »)

## Objectif

Remplacer le wizard 3 étapes de création/édition de requête par un écran unique
« live » : l'aperçu des données se met à jour automatiquement à chaque
modification (ressource, colonnes, enfants expand, jointures, filtres, tri,
tenant, limite), jusqu'à l'enregistrement. La création et l'édition partagent
le même composant (l'édition arrive pré-remplie).

## Décisions validées

- **Layout deux colonnes** : configuration à gauche, aperçu live à droite
  (toujours visible). En dessous de `lg`, les colonnes s'empilent.
- **Refresh tout-auto** : chaque changement relance l'aperçu ~600 ms après la
  dernière modification (debounce). La requête précédente en vol est annulée
  (`AbortController`). Pas de bouton « Tester » ; un petit bouton de
  rafraîchissement manuel subsiste pour les cas de timeout.
- **Nouveau composant** : `query-builder.tsx` remplace `query-wizard.tsx`
  (création + édition). Le wizard est **supprimé** — aucun code mort conservé.
- **Throttle dédié** : `queries/direct-preview` passe de `throttle:15,1`
  (partagé avec les routes LLM) à `throttle:60,1` — l'endpoint est sans LLM,
  borné par le catalogue et la limite de lignes.

## Architecture front

| Fichier | Rôle |
|---|---|
| `components/queries/query-builder.tsx` | Racine : état complet, layout deux colonnes, colonne droite (aperçu, onglets Aperçu/SQL BIP, panneau d'enregistrement) |
| `components/queries/query-config-panel.tsx` | Colonne gauche : sections Ressource / Colonnes / Données liées / Filtres / Tri / Tenant & limite |
| `hooks/use-live-preview.ts` | Debounce 600 ms + abort + `{ result, error, loading }` ; conserve le dernier résultat pendant un rafraîchissement (voile) |
| `lib/query-spec.ts` | Types (`FilterRow`, `ChildFieldsMap`, `Resource`) et helpers extraits du wizard : `filterRowsToQ`, `qToFilterRows`, `generateBipSql`, `parseLimit` |

**Flux :** état → (debounce) → `POST queries/direct-preview` → lignes +
spécification canonique (`parameters`). Le bouton Enregistrer persiste
exactement la spec du dernier aperçu réussi — on enregistre ce qu'on voit.

**Choix de ressource :** à la création, la grille de cards (recherche +
domaines) s'affiche en pleine largeur tant qu'aucune ressource n'est choisie ;
la sélection bascule en deux colonnes et lance le premier aperçu. « Changer de
ressource » revient à la grille et réinitialise l'état. En édition, arrivée
directe en deux colonnes, premier fetch au montage.

**Enregistrer :** footer de la colonne droite (nom, visibilité, bouton).
Actif seulement si le dernier aperçu a réussi **et** correspond à l'état
courant (désactivé pendant debounce/chargement/erreur).

## États de l'aperçu

- Pas de ressource → état vide « Choisissez une ressource ».
- Premier chargement → skeleton.
- Rafraîchissement → ancien tableau conservé sous un voile + spinner.
- Erreur (validation, Oracle, réseau) → `AlertError`, la config reste éditable.
- 429 (throttle) → message doux invitant à patienter.

## Backend

Aucun changement de contrat : `directPreview` (avec `expand`, `joins`,
`child_fields`) et la persistance canonique existent déjà (feature jointures
du 2026-07-17). Seul changement : le groupe de throttle de la route.

## Tests

- PHP : test de throttle ajusté (60/min pour direct-preview, 15/min inchangé
  pour preview LLM et run) ; suites existantes inchangées.
- Front : pas de framework JS dans le repo — vérification par `types:check`,
  `lint:check`, `build`, et test manuel via Herd.
