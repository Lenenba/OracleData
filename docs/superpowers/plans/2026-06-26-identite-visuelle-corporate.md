# Identité visuelle corporate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le thème gris neutre par défaut par une identité « sobre & corporate » (palette indigo feutré, police Inter, logo OracleData, radius resserré) en agissant uniquement sur les design tokens, la config de police et le logo.

**Architecture:** Tous les composants (type shadcn) consomment des CSS custom properties définies dans `resources/css/app.css`. Modifier ces tokens met à jour toute l'app de façon cohérente, sans toucher à la structure ni à la logique. La police passe par le plugin `bunny()` de `laravel-vite-plugin`. Le logo est un composant React + SVG.

**Tech Stack:** Tailwind CSS v4 (tokens OKLCH), Vite + laravel-vite-plugin (fonts Bunny), Inertia + React 19 (TSX), Blade (anti-flash).

**Spec de référence:** `docs/superpowers/specs/2026-06-26-identite-visuelle-corporate-design.md`

## Global Constraints

- Périmètre strict : palette + typo + logo + radius. **Aucune** modification de structure, layout ou logique.
- Ne **pas** toucher à `.claude/settings.json` (déjà modifié, hors sujet) ni le committer.
- Conserver les tokens `--destructive*` et `--chart-*` existants (inchangés).
- Police : `Inter`, poids `[400, 500, 600, 700]`.
- Radius cible : `--radius: 0.375rem` (= 6px).
- Espace colorimétrique : OKLCH, valeurs exactes copiées depuis la spec.
- Vérification : pas de logique unit-testable. Gates = `npm run types:check` + `npm run build` qui passent, plus revue visuelle clair/sombre.

---

### Task 1: Palette de couleurs + radius + anti-flash

Cœur du changement : remplace les tokens couleur (clair + sombre), abaisse le radius, et aligne la couleur de fond inline du Blade pour éviter un flash au chargement.

**Files:**
- Modify: `resources/css/app.css` (blocs `:root` et `.dark`, valeur `--radius`)
- Modify: `resources/views/app.blade.php` (style inline `html` / `html.dark`)

**Interfaces:**
- Consumes: rien (premier task).
- Produces: tokens `--primary`, `--background`, `--sidebar*`, `--ring`, `--radius`, etc. consommés par tous les composants et par les tasks suivants (la couleur du logo en Task 3 s'appuie sur `--sidebar-primary` / `--sidebar-primary-foreground`).

- [ ] **Step 1: Remplacer le bloc `:root` dans `resources/css/app.css`**

Remplacer les déclarations couleur existantes de `:root` (lignes ~64–98) par les valeurs ci-dessous. **Conserver** les lignes `--chart-1` … `--chart-5` déjà présentes (ne pas les supprimer). Mettre `--radius` à `0.375rem`.

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
    --chart-1: oklch(0.646 0.222 41.116);
    --chart-2: oklch(0.6 0.118 184.704);
    --chart-3: oklch(0.398 0.07 227.392);
    --chart-4: oklch(0.828 0.189 84.429);
    --chart-5: oklch(0.769 0.188 70.08);
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

- [ ] **Step 2: Remplacer le bloc `.dark` dans `resources/css/app.css`**

Remplacer les déclarations couleur de `.dark` (lignes ~100–133) par les valeurs ci-dessous. **Conserver** les `--chart-*` du mode sombre déjà présents.

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
    --chart-1: oklch(0.488 0.243 264.376);
    --chart-2: oklch(0.696 0.17 162.48);
    --chart-3: oklch(0.769 0.188 70.08);
    --chart-4: oklch(0.627 0.265 303.9);
    --chart-5: oklch(0.645 0.246 16.439);
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

- [ ] **Step 3: Aligner l'anti-flash dans `resources/views/app.blade.php`**

Dans le `<style>` inline (lignes ~23–31), remplacer les couleurs de fond `html` par celles de la nouvelle palette :

```html
<style>
    html {
        background-color: oklch(0.992 0.003 275);
    }

    html.dark {
        background-color: oklch(0.2 0.018 282);
    }
</style>
```

- [ ] **Step 4: Vérifier la compilation**

Run: `npm run build`
Expected: build réussi sans erreur (CSS compilée, manifest généré).

- [ ] **Step 5: Revue visuelle rapide**

Ouvrir l'app (Herd) sur le Dashboard et la Bibliothèque de requêtes, en clair **et** sombre. Vérifier : fond légèrement indigo, primaire indigo feutré, bordures plus carrées (radius 6px), pas de flash blanc/noir au chargement.

- [ ] **Step 6: Commit**

```bash
git add resources/css/app.css resources/views/app.blade.php
git commit -m "feat(ui): palette indigo feutre + radius resserre + anti-flash"
```

---

### Task 2: Typographie Inter

Charge la police Inter via Bunny et la déclare comme police par défaut.

**Files:**
- Modify: `vite.config.ts` (entrée `fonts: [...]`)
- Modify: `resources/css/app.css` (token `--font-sans` dans `@theme`)

**Interfaces:**
- Consumes: rien des autres tasks.
- Produces: police `Inter` disponible et appliquée globalement (`body` utilise `font-sans` → `--font-sans`).

- [ ] **Step 1: Remplacer la police dans `vite.config.ts`**

Remplacer l'entrée `bunny('Instrument Sans', ...)` (lignes ~14–18) par :

```ts
fonts: [
    bunny('Inter', {
        weights: [400, 500, 600, 700],
    }),
],
```

- [ ] **Step 2: Mettre à jour `--font-sans` dans `resources/css/app.css`**

Dans le bloc `@theme` (lignes ~11–14), remplacer `'Instrument Sans'` par `'Inter'` en tête de pile :

```css
--font-sans:
    'Inter', ui-sans-serif, system-ui, sans-serif,
    'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
    'Noto Color Emoji';
```

- [ ] **Step 3: Vérifier la compilation**

Run: `npm run build`
Expected: build réussi. Le plugin Bunny récupère Inter sans erreur.

- [ ] **Step 4: Revue visuelle**

Recharger l'app : le texte (titres, tableaux, labels) est rendu en **Inter**. Vérifier la netteté des petits textes dans les tableaux.

- [ ] **Step 5: Commit**

```bash
git add vite.config.ts resources/css/app.css
git commit -m "feat(ui): police Inter (Bunny) en remplacement d'Instrument Sans"
```

---

### Task 3: Logo OracleData (wordmark + glyphe cylindre)

Remplace le wordmark « Laravel Starter Kit » par « OracleData » et l'icône Laravel par un glyphe cylindre/base de données.

**Files:**
- Modify: `resources/js/components/app-logo.tsx`
- Modify: `resources/js/components/app-logo-icon.tsx`

**Interfaces:**
- Consumes: tokens couleur de Task 1 (le carré conteneur utilise `bg-sidebar-primary` + `text-sidebar-primary-foreground`).
- Produces: composant `AppLogo` affichant la marque OracleData ; `AppLogoIcon` rendant un cylindre en `stroke="currentColor"`.

- [ ] **Step 1: Remplacer le SVG dans `resources/js/components/app-logo-icon.tsx`**

Remplacer tout le contenu du fichier par un glyphe cylindre en trait. Le composant garde sa signature `(props: SVGAttributes<SVGElement>)` et propage `className` :

```tsx
import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            xmlns="http://www.w3.org/2000/svg"
        >
            <ellipse cx="12" cy="5.5" rx="7.5" ry="3" />
            <path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
            <path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
        </svg>
    );
}
```

- [ ] **Step 2: Mettre à jour `resources/js/components/app-logo.tsx`**

Le glyphe est désormais en `stroke` (plus en `fill`) : adapter la classe de l'icône à `text-sidebar-primary-foreground` et retirer `fill-current`. Remplacer le wordmark par « Oracle » + « Data » (Data en `text-muted-foreground`).

```tsx
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate font-semibold leading-tight">
                    Oracle<span className="text-muted-foreground">Data</span>
                </span>
            </div>
        </>
    );
}
```

- [ ] **Step 3: Vérifier types + lint + build**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: aucune erreur TypeScript/ESLint, build réussi.

- [ ] **Step 4: Revue visuelle**

Dans la sidebar : carré indigo avec cylindre blanc lisible, wordmark « Oracle » foncé + « Data » grisé. Vérifier en mode replié (icône seule) et en clair/sombre.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/app-logo.tsx resources/js/components/app-logo-icon.tsx
git commit -m "feat(ui): logo OracleData (wordmark + glyphe cylindre)"
```

---

### (Optionnel) Task 4: Nom d'application

Hors visuel strict, mais cohérent avec la marque : aligner le titre des pages.

**Files:**
- Modify: `.env` (`APP_NAME`)

- [ ] **Step 1: Définir le nom**

Mettre `APP_NAME=OracleData` dans `.env` (et `.env.example` si présent). Puis `php artisan config:clear`.

- [ ] **Step 2: Vérifier**

Recharger une page : l'onglet affiche « … OracleData » (via `config('app.name')`).

- [ ] **Step 3: Commit** (uniquement si `.env.example` est versionné)

```bash
git add .env.example
git commit -m "chore: APP_NAME OracleData"
```

---

## Notes d'exécution

- Tasks 1, 2, 3 sont **indépendants** (fichiers disjoints) : ordre conseillé 1 → 2 → 3, mais sans dépendance bloquante.
- Aucun test Pest à écrire : changement purement visuel. Les gates `types:check` / `build` protègent contre les régressions de compilation (notamment Task 3, seul changement de composant React).
- Après les changements front, l'utilisateur peut avoir besoin de `npm run dev` / `npm run build` pour voir le rendu.
