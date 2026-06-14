# NeoBrutalism Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Revise the site's NeoBrutalism look (public + `/admin` + `/jobs`) to match Rustcode's CodePen pen, and unify/revise the dark palette.

**Architecture:** Drive the change through shared tokens — `base.css` (fonts) + `theme.css` (palette + new structural tokens `--border-width`/`--shadow`/`--shadow-hover`). The component CSS already references `var(--color-shadow)`/`var(--font-head)`/`var(--font-sans)`, so token edits propagate color + fonts automatically; per-file tasks then do a structural sweep (4px borders, 8px offset shadow, hover-press) and apply `--font-prose` to long-form content. Visual layer only — no markup/JS/behavior changes.

**Tech Stack:** Plain CSS (`public/css/`), Twig templates, Vite (jobs island CSS), Google Fonts.

**Spec:** `docs/superpowers/specs/2026-06-14-nb-redesign-design.md`
**Reference pen:** https://codepen.io/rustcode/pen/YPPbxYX

**Verification note (applies to every task):** there are no automated CSS assertions. After each task, `yarn build` must succeed and the existing suites must stay green (`vendor/bin/phpunit` = 56 tests, `yarn test` = 4). Visual confirmation is the owner's final pass (Task 9). Run `docker compose up -d` if the DB is down for phpunit.

---

## Task 1: Fonts (tokens + font links)

**Files:**
- Modify: `public/css/base.css` (the `:root` font tokens)
- Modify: `app/views/partials/head.twig` (Google Fonts link)
- Modify: `app/views/jobs.twig` (Google Fonts link)

- [ ] **Step 1: Swap the font tokens in base.css**

In `public/css/base.css`, replace the three font token lines:
```css
		--font-sans: 'Space Grotesk', sans-serif;
		--font-head: 'Archivo Black', sans-serif;
		--font-mono: 'JetBrains Mono', monospace;
```
with:
```css
		--font-sans: 'Roboto Mono', ui-monospace, monospace;  /* default/UI font (chrome) */
		--font-head: 'Bebas Neue', sans-serif;                /* display headings */
		--font-prose: 'Space Grotesk', sans-serif;            /* long-form readable body */
		--font-mono: 'Roboto Mono', ui-monospace, monospace;  /* code */
```

- [ ] **Step 2: Update the Google Fonts link (public head)**

In `app/views/partials/head.twig`, replace the fonts `<link>` (currently loading `Archivo+Black&family=JetBrains+Mono&family=Space+Grotesk`) with:
```twig
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Roboto+Mono:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" />
```

- [ ] **Step 3: Update the Google Fonts link (jobs shell)**

In `app/views/jobs.twig`, replace its fonts `<link>` with the exact same line as Step 2.

- [ ] **Step 4: Build + verify**

Run: `yarn build` (expected: success). The token names are unchanged except the added `--font-prose`, so nothing breaks. Visual (Task 9): headings render in Bebas Neue, UI/chrome in Roboto Mono.

- [ ] **Step 5: Commit**

```bash
git add public/css/base.css app/views/partials/head.twig app/views/jobs.twig
git commit -m "NB redesign: Bebas Neue + Roboto Mono fonts (+ --font-prose for long-form)"
```

---

## Task 2: Light palette + structural tokens + attribution (`theme.css`)

**Files:**
- Modify: `public/css/theme.css`

- [ ] **Step 1: Add the attribution comment + rewrite the `:root` palette**

In `public/css/theme.css`, replace the top comment + the `:root { … }` light block (down to the `--color-accent-ring` / shadow lines, keeping the `--emdash-search-*` aliases) with:

```css
/*
  NeoBrutalism palette + treatment, adapted from "Basic Neo Brutalist Component
  Library" by Rustcode — https://codepen.io/rustcode/pen/YPPbxYX
  (license: https://codepen.io/license/pen/YPPbxYX). Unlayered so it overrides
  the base-layer defaults in base.css regardless of load order. Dark overrides
  sit in the :root.dark rule below.
*/

:root {
	/* --- Colors (pen palette) --- */
	--color-bg: #ffffff;            /* cards / raised surfaces */
	--color-bg-subtle: #f5f5f5;     /* page background */
	--color-text: #1a1a1a;
	--color-text-secondary: #404040;
	--color-muted: #6b6b6b;
	--color-border: #000000;
	--color-border-subtle: #cccccc;
	--color-surface: #e5e5e5;       /* gray panels (was light yellow) */
	--color-accent: #facc15;        /* yellow-400 */
	--color-accent-hover: #eab308;  /* yellow-500 */
	--color-on-accent: #000000;
	--color-accent-ring: color-mix(in srgb, var(--color-accent) 40%, transparent);
	--color-error: #ef4444;         /* red-500 */
	--color-success: #22c55e;       /* green-500 */

	/* --- NeoBrutalism structural tokens --- */
	--color-shadow: var(--color-border);          /* black in light, light in dark */
	--border-width: 4px;
	--radius: 4px;
	--shadow: 8px 8px 0 var(--color-shadow);
	--shadow-hover: 6px 6px 0 var(--color-shadow);

	/* EmDash search widget follows palette */
	--emdash-search-bg: var(--color-bg);
	--emdash-search-text: var(--color-text);
	--emdash-search-muted: var(--color-muted);
	--emdash-search-border: var(--color-border);
	--emdash-search-hover: var(--color-surface);
	--emdash-search-highlight: var(--color-accent);
}
```

Note: `--radius` is also defined in `base.css` (`4px`); theme.css redefining it to `4px` is harmless and keeps the NB tokens together. (If a lint flags the dup, leave base.css's `--radius` and drop it here — the value is identical.)

- [ ] **Step 2: Bump the heading rule to the pen's letter-spacing**

In the same file, the heading rule becomes:
```css
h1, h2, h3, h4, h5, h6 {
	font-family: var(--font-head);
	letter-spacing: 2px;
}
```

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success). Visual: accent is now yellow-400, surfaces white/gray, offset shadows black. (Dark mode is Task 3.)

- [ ] **Step 4: Commit**

```bash
git add public/css/theme.css
git commit -m "NB redesign: pen light palette + structural tokens + attribution"
```

---

## Task 3: Unify + revise the dark palette

**Files:**
- Modify: `public/css/theme.css` (`:root.dark`)
- Modify: `public/css/base.css` (remove its two competing dark blocks)

- [ ] **Step 1: Rewrite `theme.css`'s `:root.dark`**

Replace the existing `:root.dark { … }` block in `public/css/theme.css` with:
```css
:root.dark {
	--color-bg: #1e1e1e;            /* cards */
	--color-bg-subtle: #121212;     /* page */
	--color-text: #f5f5f5;
	--color-text-secondary: #c0c0c0;
	--color-muted: #909090;
	--color-border: #f5f5f5;        /* light borders */
	--color-border-subtle: #444444;
	--color-surface: #2a2a2a;
	--color-accent: #facc15;
	--color-accent-hover: #eab308;
	--color-on-accent: #000000;
	/* --color-shadow inherits var(--color-border) → light offset on dark */
}
```

- [ ] **Step 2: Remove base.css's conflicting dark definitions**

In `public/css/base.css`, DELETE both dark blocks so `theme.css` is the single source of truth:
- the `@media (prefers-color-scheme: dark) { :root:not(.light) { … } }` block, and
- the explicit `:root.dark { … }` block (the one with the blue `--color-accent: #4d9fff`).

(Dark mode is driven by the `.dark` class set by the FOUC/theme script — including for system-dark users, since that script adds `.dark` on `prefers-color-scheme: dark` when no cookie is set. Task 7 ensures that script is on every surface.)

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success). `grep -n "4d9fff\|prefers-color-scheme" public/css/base.css` → empty. Visual (Task 9): toggling dark gives the single coherent dark palette with light/white offset shadows.

- [ ] **Step 4: Commit**

```bash
git add public/css/theme.css public/css/base.css
git commit -m "NB redesign: unified dark palette (single source in theme.css)"
```

---

## Task 4: Structural sweep — `base.css` + `layout.css`

**Files:**
- Modify: `public/css/base.css` (button shadow tokens)
- Modify: `public/css/layout.css` (nav, search, footer, buttons)

- [ ] **Step 1: Repoint base.css button-shadow tokens to the NB shadow**

In `public/css/base.css`, the `--shadow-btn-active: 0 1px 2px rgba(0,0,0,0.05);` and `--shadow-dropdown: 0 8px 30px rgba(0,0,0,0.12);` tokens are soft drop-shadows. Repoint them to the NB system:
```css
		--shadow-dropdown: var(--shadow);
		--shadow-btn-active: var(--shadow-hover);
```
(They keep their names so existing references still resolve; only the values become NB.)

- [ ] **Step 2: layout.css — thicken borders + NB shadow + press**

In `public/css/layout.css`, apply these replacements (the structural rules at the lines from the inventory — nav/search input ~77-78, dropdown ~104-106, buttons ~260-286):
- Every `border: 1px solid var(--color-border)` → `border: var(--border-width) solid var(--color-border)`.
- The search/nav focus and dropdown that use `var(--shadow-dropdown)` now resolve to the NB shadow (from Step 1) — no change needed there.
- For interactive buttons/links that have `box-shadow`, ensure a hover state:
  ```css
  box-shadow: var(--shadow);
  ```
  and on `:hover`:
  ```css
  box-shadow: var(--shadow-hover);
  transform: translate(2px, 2px);
  ```
  Add `transition: transform 0.12s ease, box-shadow 0.12s ease;` to those elements if not already present.
- `border-radius: var(--radius)` / `var(--radius-lg)` stay (already small).

Read `layout.css` and apply the above to the header/nav container, the theme-switcher buttons (`.theme-btn`), the search box, and the footer chrome — the elements that read as "cards/buttons."

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success). Visual: nav/search/buttons get 4px black borders + offset shadow + press.

- [ ] **Step 4: Commit**

```bash
git add public/css/base.css public/css/layout.css
git commit -m "NB redesign: layout/base chrome — 4px borders, NB shadow + hover-press"
```

---

## Task 5: Structural sweep — `pages.css`

**Files:**
- Modify: `public/css/pages.css`

`pages.css` already uses the offset-shadow pattern (`2px solid var(--color-border)` + `Npx Npx 0 0 var(--color-shadow)` with a hover) at several card rules (~73-85, ~140, ~235-236, ~284, ~429-435). Normalize them to the tokens + the pen's 8px/press, and switch long-form content body to `--font-prose`.

- [ ] **Step 1: Borders → token**

In `public/css/pages.css`, replace every `border: 2px solid var(--color-border)` with `border: var(--border-width) solid var(--color-border)`.

- [ ] **Step 2: Offset shadows → token**

Replace every card `box-shadow: 4px 4px 0 0 var(--color-shadow)` and `box-shadow: 3px 3px 0 0 var(--color-shadow)` with `box-shadow: var(--shadow)`. Replace the corresponding `:hover` `box-shadow: 6px 6px 0 0 var(--color-shadow)` with `box-shadow: var(--shadow-hover); transform: translate(2px, 2px);` (keep/ensure `transition: transform 0.12s ease, box-shadow 0.12s ease;` on those rules).

- [ ] **Step 3: Long-form body → prose font**

For the page/content body text containers in `pages.css` (the prose/paragraph blocks that currently use `font-family: var(--font-sans)` for readable copy — NOT headings, NOT labels/metadata/buttons), change `var(--font-sans)` → `var(--font-prose)`. Headings stay `var(--font-head)`; UI/labels stay `var(--font-sans)` (Roboto Mono). Read the file and apply judiciously to the genuine long-form copy blocks.

- [ ] **Step 4: Build + verify**

Run: `yarn build` (success). `grep -nE "2px solid var\(--color-border\)|[0-9]px [0-9]px 0 0 var\(--color-shadow\)" public/css/pages.css` → empty (all moved to tokens). Visual: page cards match the pen; body copy is Space Grotesk, headings Bebas.

- [ ] **Step 5: Commit**

```bash
git add public/css/pages.css
git commit -m "NB redesign: pages.css cards → token shadow/border + press; prose font for copy"
```

---

## Task 6: Structural sweep — `article.css`

**Files:**
- Modify: `public/css/article.css`

Blog post styling. Long-form post body → Space Grotesk; code stays Roboto Mono; card-like elements get the NB treatment.

- [ ] **Step 1: Post body → prose font**

In `public/css/article.css`, the article/post body copy (the prose container and its `p`, `li`, blockquote text) → `font-family: var(--font-prose)`. Headings keep `var(--font-head)`. Code (`pre`, `code`) keeps `var(--font-mono)` (Roboto Mono).

- [ ] **Step 2: NB treatment on card-like elements**

For elements with a border/box (e.g. the code block at ~164-167 `border: 1px solid var(--color-border)`, callouts, the post header card), change `1px` borders → `var(--border-width)` and add `box-shadow: var(--shadow)` where a raised card reads correctly (use judgment — body paragraphs and inline elements do NOT get borders/shadows; only genuine card/box/code-block containers).

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success). Visual: post headings Bebas, body Space Grotesk (readable), code Roboto Mono in a 4px-bordered block.

- [ ] **Step 4: Commit**

```bash
git add public/css/article.css
git commit -m "NB redesign: article.css — prose body, NB code/card treatment"
```

---

## Task 7: Rework `admin.css` onto NB tokens + theme script on admin pages

**Files:**
- Modify: `public/css/admin.css`
- Modify: `app/views/admin/layout.twig`, `app/views/admin/login.twig` (FOUC/theme script)

- [ ] **Step 1: Rebuild admin.css on the shared NB tokens**

Replace the `:root { --a-* }` block and the admin component rules in `public/css/admin.css` so they use the shared tokens + NB treatment instead of the standalone `--a-*` plain theme. Concretely:
- Delete the `:root { --a-border:#d0d0d0; --a-bg:#fff; --a-text:#1a1a1a; --a-accent:#0066cc; --a-muted:#666; }` line.
- `body.admin` → `font-family: var(--font-sans); color: var(--color-text); background: var(--color-bg-subtle);`
- `.admin-card`, `.admin-login`, `.admin-form`, `.admin-media-grid figure`: `background: var(--color-bg); border: var(--border-width) solid var(--color-border); border-radius: var(--radius); box-shadow: var(--shadow);` (drop the `--a-*` refs).
- Inputs (`.admin-login input, .admin-form input/textarea/select`): `border: var(--border-width) solid var(--color-border); border-radius: var(--radius);`
- Buttons (`.admin-login button, .admin-form button, .admin-actions button`): `background: var(--color-accent); color: var(--color-on-accent); border: var(--border-width) solid var(--color-border); border-radius: var(--radius); box-shadow: var(--shadow);` + a `:hover { box-shadow: var(--shadow-hover); transform: translate(2px,2px); }`.
- `.admin-logout button`: outline button — `border: var(--border-width) solid var(--color-border); color: var(--color-text); background: var(--color-bg);`
- `.admin-form textarea` keeps `font-family: var(--font-mono)`.
- Replace any remaining hardcoded `#fff`/`#555`/`#ddd`/`#f5f5f5` with the matching `--color-*` token.

NOTE: `admin.css` must now rely on `base.css` + `theme.css` tokens, which the admin templates already load only as `/css/admin.css`. So **Step 2 also adds the base/theme CSS to the admin templates** (or the tokens won't resolve).

- [ ] **Step 2: Load base/theme tokens + the theme script on admin templates**

In `app/views/admin/layout.twig` AND `app/views/admin/login.twig` `<head>` (both currently load only `/css/admin.css`):
- Add the fonts `<link>` (same as Task 1 Step 2), `/css/base.css`, and `/css/theme.css` BEFORE `/css/admin.css`.
- Add the FOUC/theme script (the same `(function(){ … document.documentElement.classList.add(theme) … })()` snippet used in `app/views/layout.twig` / `app/views/jobs.twig`) so dark mode + system-dark work on `/admin`.

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success), `vendor/bin/phpunit --filter AdminAuthTest` (login page still 200). Visual (Task 9): `/admin` now matches the NB look in light + dark.

- [ ] **Step 4: Commit**

```bash
git add public/css/admin.css app/views/admin/layout.twig app/views/admin/login.twig
git commit -m "NB redesign: admin onto shared NB tokens + theme script"
```

---

## Task 8: Align the jobs island CSS

**Files:**
- Modify: `islands/jobtracker/styles/global.css`

The jobs `global.css` already aliases the site tokens, so the new palette/fonts flow in automatically. Align its structural values to the new tokens (the JT-7 reskin hardcoded `2px` borders, a `6px`-ish radius, and a `4px 4px 0` shadow).

- [ ] **Step 1: Point the tracker's structural tokens at the site tokens**

In `islands/jobtracker/styles/global.css`, in the `:root` aliasing block, change:
- `--radius: 6px;` → `--radius: var(--radius);` is circular — instead set `--radius: 4px;` (matches the pen) OR leave as the tracker's own; simplest: set `--radius: 4px;`.
- `--shadow: 4px 4px 0 var(--color-shadow);` → `--shadow: 8px 8px 0 var(--color-shadow);` (match the pen's 8px).
Add a hover-shadow alias if used: `--shadow-hover: 6px 6px 0 var(--color-shadow);`

- [ ] **Step 2: Borders → 4px + add the hover-press where appropriate**

Replace `2px solid var(--color-border)` occurrences in this file with `4px solid var(--color-border)`. On interactive cards/buttons (`.btn`, `.card` if interactive) add `:hover { box-shadow: var(--shadow-hover); transform: translate(2px,2px); }` consistent with the rest of the site. (Charts/donut: keep the donut round; don't add press to static chart blocks.)

- [ ] **Step 3: Build + verify**

Run: `yarn build` (success), `yarn test` (4 island tests still green). Visual (Task 9): `/jobs` matches the new NB look in light + dark.

- [ ] **Step 4: Commit**

```bash
git add islands/jobtracker/styles/global.css
git commit -m "NB redesign: align jobs island to 8px shadow / 4px border / press"
```

---

## Task 9: Final verification (build, suites, visual E2E)

- [ ] **Step 1: Full build + suites**

Run: `yarn build` (success), `vendor/bin/phpunit` (56 green), `yarn test` (4 green). `grep -rn "Archivo\|JetBrains\|#ffdb33\|#fae583\|4d9fff" public/css islands/jobtracker app/views/partials/head.twig app/views/jobs.twig` → empty (no leftover old fonts/colors).

- [ ] **Step 2: Manual visual E2E (owner)**

`docker compose up -d && php -S 127.0.0.1:8088 -t public public/router.php`. Across **light and dark** (theme switcher) verify: public home + a blog post + a page; `/admin` (sign in); `/jobs`. Confirm 4px black borders, 8px offset shadow + hover-press, Bebas Neue headings, Roboto Mono chrome vs Space Grotesk article prose, yellow-400 accent, and the dark palette's light/white offset shadows. Note anything off for polish.

---

## Self-Review Notes

- **Spec coverage:** fonts §1 → Task 1; light palette + structural tokens + attribution §2/§5 → Task 2; unified dark §3 → Task 3; component rollout §4 → Tasks 4 (layout/base), 5 (pages), 6 (article), 7 (admin), 8 (jobs); attribution → Task 2 Step 1; open-check #4 (theme script on admin) → Task 7 Step 2; verification → Task 9.
- **Placeholders:** token tasks carry exact CSS; component sweeps are concrete find/replace operations against the real values found in each file (not hand-waves) + browser verification — appropriate for a design pass where final pixel polish is the owner's. The "use judgment for which blocks are prose vs UI" notes are inherent to CSS restyling and bounded by reading the file.
- **Type/name consistency:** new tokens `--border-width`/`--shadow`/`--shadow-hover`/`--font-prose`/`--color-error`/`--color-success` defined in Tasks 1–2 and consumed in Tasks 4–8; `--color-shadow: var(--color-border)` (Task 2) drives the per-mode shadow used everywhere; the fonts `<link>` line is identical across head.twig/jobs.twig/admin templates.
