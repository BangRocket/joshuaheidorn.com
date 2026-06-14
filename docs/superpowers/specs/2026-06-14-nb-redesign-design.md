# NeoBrutalism Redesign — Design

**Date:** 2026-06-14
**Branch:** `php-svelte-rewrite`
**Status:** Approved (design); pending spec review → implementation plan

## Goal

Revise the site's NeoBrutalism look — across the **public site, the `/admin` pages,
and the `/jobs` Job Tracker** — to match the design of Rustcode's "Basic Neo
Brutalist Component Library" CodePen (https://codepen.io/rustcode/pen/YPPbxYX),
and revise + unify the dark-mode palette. This is purely a **visual layer**
change (design tokens + component styling); layout structure, copy, and behavior
are untouched.

## Reference (the pen's system)

- `.neo-brutalist`: `border: 4px solid #000`, `box-shadow: 8px 8px 0 #000`,
  `border-radius: 4px`, hover → `box-shadow: 6px 6px 0 #000; transform: translate(2px,2px)`.
- Headings: **Bebas Neue** (`letter-spacing: 2px`). Body: **Roboto Mono**.
- Palette: page `#f5f5f5`, white cards, gray panels (gray-100/200/300), text
  `#1a1a1a`, **accent `#facc15`** (Tailwind yellow-400), semantic `#ef4444`
  (red-500) / `#22c55e` (green-500), black/white nav. No dark mode.

## Decisions (from brainstorming)

- **Fidelity:** match the pen's palette AND style exactly (with the body-font and
  dark-mode adaptations below).
- **Scope:** everything — shared tokens *and* component CSS (public, admin, jobs).
- **Body type:** Mono UI + readable long-form. Bebas Neue headings; **Roboto Mono**
  for chrome/UI/buttons/labels/cards/code; **Space Grotesk** kept for long-form
  prose (blog post / page content) so articles stay readable.
- **Shadow:** black `8px 8px 0` offset with the hover-press (exact to the pen).
- **Dark shadow:** `--color-shadow: var(--color-border)` → black offset in light,
  light offset in dark (a pure-black shadow is invisible on dark; the pen gives no
  dark guidance, so this is the chosen rule).
- **Admin:** reworked onto the shared NB tokens (no longer a separate plain theme).
- **Attribution:** a credit comment in `theme.css` for the source pen + license.

## Architecture

The site already separates **`base.css`** (font tokens, type scale, base element
styles, button tokens) from **`theme.css`** (the NeoBrutalism palette; unlayered so
it overrides `base.css`'s layered defaults). The redesign works through these
tokens so all three surfaces inherit it, then updates the component CSS that draws
the cards/buttons/surfaces.

### 1. Fonts

Load **Bebas Neue + Roboto Mono + Space Grotesk** via the Google Fonts `<link>`
(update it in `app/views/partials/head.twig` and `app/views/jobs.twig`); drop
Archivo Black + JetBrains Mono. In `base.css`:

- `--font-head: 'Bebas Neue', sans-serif;` (headings; the existing `theme.css`
  heading rule keeps `font-family: var(--font-head)` and `letter-spacing` → bump to
  the pen's `2px`).
- `--font-sans: 'Roboto Mono', ui-monospace, monospace;` — the **default/UI font**.
  `body { font-family: var(--font-sans); }` makes the site chrome mono.
- `--font-prose: 'Space Grotesk', sans-serif;` — **new**, applied only to long-form
  content containers in `article.css`/`pages.css` (post body, page body).
- `--font-mono: 'Roboto Mono', monospace;` — code/`<pre>`.

### 2. Light palette (`theme.css`)

```
--color-bg:          #ffffff;   /* cards / raised surfaces (white) */
--color-bg-subtle:   #f5f5f5;   /* page background (pen body) */
--color-surface:     #e5e5e5;   /* gray panels (was light yellow) */
--color-text:        #1a1a1a;
--color-text-secondary: #404040;
--color-muted:       #6b6b6b;
--color-border:      #000000;
--color-border-subtle: #cccccc;
--color-accent:      #facc15;   /* yellow-400 (was #ffdb33) */
--color-accent-hover:#eab308;   /* yellow-500 */
--color-on-accent:   #000000;
--color-shadow:      var(--color-border);   /* → black in light */
--color-error:       #ef4444;   /* new (red-500) */
--color-success:     #22c55e;   /* new (green-500) */
```

Structural tokens (new, in `theme.css` so all surfaces share them):
```
--border-width: 4px;
--radius:       4px;
--shadow:        8px 8px 0 var(--color-shadow);
--shadow-hover:  6px 6px 0 var(--color-shadow);
/* hover also applies: transform: translate(2px, 2px); */
```

Yellow is now **only** the accent (buttons/active/highlights), not surfaces — the
`--color-surface` yellow and the yellow offset shadow both go away.

### 3. Dark palette (revised + unified, `theme.css` `:root.dark`)

Remove the **conflicting** dark definitions in `base.css` (both its `:root.dark`
block and its `@media (prefers-color-scheme: dark)` block) so `theme.css` is the
single source of truth. (System-dark with no explicit choice currently lands on
the blue `base.css` theme — that path is eliminated; dark mode is driven solely by
the cookie/`.dark` switcher, matching the FOUC script.)

```
:root.dark {
  --color-bg:          #1e1e1e;   /* cards (raised) */
  --color-bg-subtle:   #121212;   /* page */
  --color-surface:     #2a2a2a;   /* panels */
  --color-text:        #f5f5f5;
  --color-text-secondary: #c0c0c0;
  --color-muted:       #909090;
  --color-border:      #f5f5f5;   /* light borders */
  --color-border-subtle: #444444;
  --color-accent:      #facc15;   /* yellow pops on dark */
  --color-accent-hover:#eab308;
  --color-on-accent:   #000000;
  /* --color-shadow inherits var(--color-border) → light offset on dark */
}
```

### 4. Component CSS rollout (the "everything" scope)

Apply one consistent NB treatment — `border: var(--border-width) solid
var(--color-border)`, `box-shadow: var(--shadow)`, `border-radius: var(--radius)`,
and the hover-press (`box-shadow: var(--shadow-hover); transform: translate(2px,2px)`
on interactive cards/buttons) — to the card/button/input/nav/surface rules in:

- **`base.css`** — button styles + base element resets; replace the old
  `--shadow-btn*` tokens with the NB `--shadow`.
- **`layout.css`** — header/nav, footer, theme switcher buttons, the search widget.
- **`pages.css`** — content cards/tiles (home, project tiles, list cards). Apply
  `--font-prose` to page body copy.
- **`article.css`** — blog post: Bebas headings, **Space Grotesk** prose body,
  Roboto Mono code blocks.
- **`admin.css`** — drop the standalone `--a-*` plain theme; rebuild the admin
  cards/forms/buttons/login on the shared NB tokens + treatment.
- **`islands/jobtracker/styles/global.css`** — already token-aliased, so the new
  palette flows in automatically; align its `--border-width`/`--shadow`/radius and
  add the hover-press so it matches (it currently uses 2px/6px-radius from the JT-7
  reskin).

### 5. Attribution

Add a header comment to `public/css/theme.css` crediting the source:

```
/*
  NeoBrutalism palette + treatment adapted from "Basic Neo Brutalist Component
  Library" by Rustcode — https://codepen.io/rustcode/pen/YPPbxYX
  (license: https://codepen.io/license/pen/YPPbxYX)
*/
```

## Out of scope

- Layout/markup structure, page copy, JS behavior (visual layer only).
- New components or pages.
- Changing the theme-switcher mechanism (cookie + `.dark` class) — only the
  dark *colors* are revised.

## Verification

No automated CSS assertions; the existing PHPUnit (56) + Vitest (4) suites must
stay green (this touches CSS + the font `<link>`s + token names, not logic). The
build (`yarn build`) must succeed. Visual verification by the owner across:
public home/post/page, `/admin`, and `/jobs` — in **light and dark** — confirming
the 4px black borders, the 8px offset shadow + hover-press, Bebas Neue headings,
Roboto Mono UI vs Space Grotesk prose, the yellow-400 accent, and a coherent dark
palette with the light/white offset shadow.

## Open implementation-time checks

1. Which exact selectors in `layout.css`/`pages.css`/`article.css` are the
   "card/surface" elements that should get borders+shadow (vs flat text blocks) —
   determined by reading each file.
2. Whether any current rule hardcodes the old yellow shadow / `#ffdb33` / Archivo
   Black directly (rather than via tokens) and must be updated in place.
3. The `--shadow-btn`/`--shadow-btn-active` tokens in `base.css` — repointed to the
   NB shadow or removed if unused.
4. Since `base.css`'s `@media (prefers-color-scheme: dark)` block is removed, dark
   mode is driven solely by the `.dark` class. Confirm the FOUC/theme script (the
   cookie → `.dark` snippet) runs on **every** surface — public `layout.twig`
   (present), `jobs.twig` (present), and the admin templates (`admin/layout.twig`,
   `admin/login.twig`) — so system-dark + toggled dark work on `/admin` too. Add it
   to the admin templates if missing.
