# Identité visuelle « sobre & corporate » — OracleData

**Date :** 2026-06-26
**Statut :** Validé (en attente de revue finale de la spec)
**Périmètre :** Palette de couleurs + typographie + logo + radius. Aucune modification de structure, de layout ou de logique.

## Objectif

Remplacer le thème gris neutre par défaut du starter kit Laravel par une identité visuelle propre, **sobre et corporate**, qui donne du caractère à l'application sans toucher à sa structure. Le changement passe presque entièrement par les **design tokens** : tous les composants (type shadcn) consomment déjà ces tokens, donc l'app entière se met à jour de façon cohérente et automatique.

## Décisions validées

- **Palette : C — Indigo feutré / Finance.** Indigo désaturé (faible chroma), neutres légèrement teintés indigo. Ambiance retenue, sérieuse, façon finance/données sensibles.
- **Typographie : Inter.** Standard corporate, excellente lisibilité en petit et dans les tableaux de données.
- **Logo : wordmark « OracleData » + glyphe cylindre/base de données** dans un carré arrondi en couleur primaire (monochrome).
- **Radius resserré :** `--radius` passe de `0.625rem` (10px) à `0.375rem` (6px) pour un rendu plus carré et corporate.

## Fichiers impactés

1. `resources/css/app.css` — tokens couleur (`:root` + `.dark`), `--font-sans`, `--radius`.
2. `vite.config.ts` — police chargée via `bunny()` : `Instrument Sans` → `Inter`.
3. `resources/views/app.blade.php` — couleur de fond `html` inline (anti-flash) alignée sur la nouvelle palette.
4. `resources/js/components/app-logo.tsx` — texte « Laravel Starter Kit » → « OracleData ».
5. `resources/js/components/app-logo-icon.tsx` — SVG Laravel → glyphe cylindre.

> Optionnel (hors visuel strict) : `APP_NAME` dans `.env` → `OracleData` (utilisé par `<title>` via `config('app.name')`).

## 1. Tokens couleur — `resources/css/app.css`

Remplacer intégralement les blocs `:root` et `.dark`. Les valeurs `--destructive` (rouge) sont conservées telles quelles. Les `--chart-*` sont conservés tels quels (non utilisés actuellement ; pourront être retintés plus tard si des graphiques sont ajoutés).

### `:root` (clair)

```css
:root {
    --background: oklch(0.992 0.003 275);
    --foreground: oklch(0.19 0.02 280);
    --card: oklch(1 0 0);
    --card-foreground: oklch(0.19 0.02 280);
    --popover: oklch(1 0 0);
    --popover-foreground: oklch(0.19 0.02 280);
    --primary: oklch(0.42 0.08 277);
    --primary-foreground: oklch(0.99 0.005 277);
    --secondary: oklch(0.96 0.008 275);
    --secondary-foreground: oklch(0.3 0.025 280);
    --muted: oklch(0.963 0.007 275);
    --muted-foreground: oklch(0.5 0.018 280);
    --accent: oklch(0.95 0.014 275);
    --accent-foreground: oklch(0.32 0.04 280);
    --destructive: oklch(0.577 0.245 27.325);
    --destructive-foreground: oklch(0.577 0.245 27.325);
    --border: oklch(0.905 0.01 275);
    --input: oklch(0.905 0.01 275);
    --ring: oklch(0.52 0.07 277);
    --radius: 0.375rem;
    --sidebar: oklch(0.974 0.006 276);
    --sidebar-foreground: oklch(0.23 0.02 280);
    --sidebar-primary: oklch(0.42 0.08 277);
    --sidebar-primary-foreground: oklch(0.99 0.005 277);
    --sidebar-accent: oklch(0.93 0.016 276);
    --sidebar-accent-foreground: oklch(0.3 0.025 280);
    --sidebar-border: oklch(0.9 0.01 275);
    --sidebar-ring: oklch(0.52 0.07 277);
}
```

> Les `--chart-*` existants restent dans `:root` (inchangés).

### `.dark`

```css
.dark {
    --background: oklch(0.2 0.018 282);
    --foreground: oklch(0.96 0.004 277);
    --card: oklch(0.232 0.02 282);
    --card-foreground: oklch(0.96 0.004 277);
    --popover: oklch(0.232 0.02 282);
    --popover-foreground: oklch(0.96 0.004 277);
    --primary: oklch(0.62 0.1 280);
    --primary-foreground: oklch(0.16 0.02 282);
    --secondary: oklch(0.288 0.02 282);
    --secondary-foreground: oklch(0.91 0.01 278);
    --muted: oklch(0.278 0.018 282);
    --muted-foreground: oklch(0.71 0.018 278);
    --accent: oklch(0.32 0.028 280);
    --accent-foreground: oklch(0.94 0.01 278);
    --destructive: oklch(0.396 0.141 25.723);
    --destructive-foreground: oklch(0.637 0.237 25.331);
    --border: oklch(0.305 0.018 282);
    --input: oklch(0.305 0.018 282);
    --ring: oklch(0.55 0.09 280);
    --sidebar: oklch(0.176 0.018 284);
    --sidebar-foreground: oklch(0.93 0.008 278);
    --sidebar-primary: oklch(0.62 0.1 280);
    --sidebar-primary-foreground: oklch(0.16 0.02 282);
    --sidebar-accent: oklch(0.3 0.028 280);
    --sidebar-accent-foreground: oklch(0.94 0.01 278);
    --sidebar-border: oklch(0.278 0.018 282);
    --sidebar-ring: oklch(0.55 0.09 280);
}
```

> Les `--chart-*` existants restent dans `.dark` (inchangés).

### `--font-sans`

Dans le bloc `@theme`, remplacer `'Instrument Sans'` par `'Inter'` en tête de la pile :

```css
--font-sans:
    'Inter', ui-sans-serif, system-ui, sans-serif,
    'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
    'Noto Color Emoji';
```

## 2. Police — `vite.config.ts`

Remplacer le chargement de police par Inter (ajout du poids 700 pour les titres) :

```ts
fonts: [
    bunny('Inter', {
        weights: [400, 500, 600, 700],
    }),
],
```

## 3. Anti-flash — `resources/views/app.blade.php`

Le `<style>` inline fixe la couleur de fond du `html` avant le chargement de la CSS. Aligner sur la nouvelle palette pour éviter un flash blanc/noir :

```html
html { background-color: oklch(0.992 0.003 275); }
html.dark { background-color: oklch(0.2 0.018 282); }
```

## 4. Logo — wordmark

`resources/js/components/app-logo.tsx` : remplacer le texte « Laravel Starter Kit » par « OracleData ». Garder la structure (carré primaire + icône). Wordmark figé (conforme à la maquette validée) : **Oracle** en `font-semibold` couleur de texte normale + **Data** en `text-muted-foreground`, dans un seul `<span>` (ex. `Oracle<span className="text-muted-foreground">Data</span>`).

## 5. Icône — glyphe cylindre

`resources/js/components/app-logo-icon.tsx` : remplacer le `path` Laravel par un glyphe **cylindre / base de données** (trois disques empilés), tracé en `stroke` pour rester net à petite taille. SVG de référence (validé en maquette) :

```html
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
  <ellipse cx="12" cy="5.5" rx="7.5" ry="3"/>
  <path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6"/>
  <path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6"/>
</svg>
```

> Le composant reçoit `className`. Adapter la couleur du trait au conteneur (le carré utilise `bg-sidebar-primary` + texte clair) : utiliser `stroke="currentColor"` et laisser la classe gérer la couleur, plutôt que le `fill-current` actuel.

## Vérification

Comme il s'agit de tokens visuels (CSS/SVG), pas de logique testable unitairement. Validation par :

1. `npm run build` (ou `npm run dev`) — la compilation passe, la police Inter est bien injectée.
2. Revue visuelle : Dashboard, Bibliothèque de requêtes, page d'exécution, Tenants, écrans d'auth et de paramètres — en **clair et sombre**.
3. Vérifier les états : hover/focus (ring indigo), badges, boutons primaire/ghost, tableaux.
4. Pas de flash de couleur au chargement (anti-flash blade aligné).

## Hors périmètre (non traité ici)

- Remplissage du Dashboard (reste en motifs placeholder).
- Polish du shell (espacements, ombres, refonte de composants).
- Retinte des couleurs de graphiques `--chart-*`.
- Mode clair/sombre par défaut : comportement actuel inchangé (suit le système).
