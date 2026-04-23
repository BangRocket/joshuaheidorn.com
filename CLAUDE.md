# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Personal resume / portfolio / blog site for Joshua Heidorn (joshuaheidorn.com). Deployed to Joshua's **paid** Cloudflare account. Not static — runs on Workers with D1 + R2.

Full design rationale lives in `docs/plans/2026-04-23-personal-site-design.md`. Read that before making architectural decisions.

## Stack

- **Astro** (SSR mode on Cloudflare)
- **[EmDash CMS](https://github.com/emdash-cms/emdash)** — Astro-native CMS, installed as an **upstream dependency** (do not fork; do not vendor)
- **Cloudflare Workers + D1 + R2** — paid tier, Dynamic Workers enabled (`worker_loaders` stays on even before plugins exist, to keep the option open)
- **TypeScript** for EmDash config + any plugins. Astro pages/components can be `.astro` or `.jsx` — stay in JS where comfortable
- **RetroUI** (NeoBrutalism component library) via Astro's React islands — full restyle of the Blog template
- **yarn (1.x classic)** — enforced. Joshua's `~/package.json` pins `packageManager: yarn@1.22.22`, which blocks pnpm in this subtree. Do not try to switch to pnpm/npm without discussing.

## Status

**Not yet scaffolded.** First code session: scaffold via EmDash's Blog template, then port Portfolio template's project-grid + case-study + tag-filtering features manually. See the design doc for the full route table and merge plan.

## Content model (hybrid)

- **In EmDash (Portable Text):** `posts`, `projects`
- **In repo (JSON):** `src/data/resume.json` (experience, education), `src/data/skills.json` (grouped by category)
- Resume + skills render at build time by importing JSON into Astro pages. Posts + projects fetch from EmDash at request time (or build time, depending on how EmDash exposes them).

Don't move resume/skills into EmDash without revisiting the design — the hybrid split was a deliberate call (git diff for resume edits, CMS UX for content edits).

## Commands (post-scaffold)

Verify against `package.json` before relying on these; EmDash's scaffold may customize them:

- `yarn install` (or just `yarn`)
- `yarn dev` — local dev with Wrangler + Astro
- `yarn build` — production build
- `yarn preview` — preview build locally
- `yarn deploy` or `yarn wrangler deploy` — ship to Cloudflare

No test runner at launch. Ask before adding one.

## Gap-fill posture

EmDash will have gaps. When you (or future Claude) hit one:

1. **First**: check if it's addressable via EmDash's plugin system. Plugins go in `/plugins/`, are capability-scoped, run in sandboxed Worker isolates.
2. **If plugin can't do it**: open an issue upstream. Possibly a PR.
3. **Last resort**: fork. Forks bitrot on a moving upstream — default to avoiding.

Plugin work is out of scope for v1. But do not disable `worker_loaders` in `wrangler.jsonc` — we want plugins to be a deploy-config switch later, not a code change.

## RetroUI integration notes

- RetroUI's CLI was built assuming Next.js / Vite React layouts. In Astro it may write to the wrong paths or `tsconfig` aliases. First RetroUI task should be a spike: add one component, verify it renders.
- If the CLI misbehaves, copy RetroUI component source from their docs by hand into `src/components/ui/`. RetroUI components are owned source once added — edit them freely.
- Use `client:visible` (or no client directive) on RetroUI components whenever possible; don't reach for `client:load` unless the component actually needs JS on first paint.
- RetroUI's retro styling applies to **chrome** (cards, buttons, hero blocks, skill chips). Blog post body prose stays readable and relatively un-retro.

## Out of scope for v1

Comments, multi-user auth, analytics, search over projects, PDF export of resume, i18n. Do not build these speculatively.

## Conventions

- Functional React components only
- `git add .` is forbidden. Add files by name.
- Tailwind utility classes in JSX/Astro; global CSS only where RetroUI's theme tokens or font imports need it
- Keep the component tree shallow — this is a resume site, not an app. No abstractions until 3+ concrete usages.
