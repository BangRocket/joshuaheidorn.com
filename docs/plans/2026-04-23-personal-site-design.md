# Personal Site Design — joshuaheidorn.com

**Date:** 2026-04-23
**Status:** Approved by Joshua, ready for implementation planning.

## Goal

A personal site Joshua can link from his resume and semi-professional profiles. Showcases work history + skills + project portfolio, and doubles as a blog. Built on [EmDash](https://github.com/emdash-cms/emdash) — "WordPress successor" Astro+Cloudflare CMS — with room to contribute gap-fills back to EmDash later.

## Decisions (what was picked and why)

| Decision | Choice | Why |
|---|---|---|
| CMS | EmDash, upstream dep, no fork | Fill gaps via plugins when they come up; forks bitrot |
| Deploy target | Cloudflare Workers + D1 + R2, paid tier | Joshua already pays; Dynamic Workers enable plugin sandbox later |
| Starting template | Blog template | Blog is the center of gravity |
| Template merge | Manually port Portfolio template features into Blog scaffold | Simpler than maintaining both; no cross-template plugin magic needed |
| Design system | RetroUI (NeoBrutalism), full restyle via Astro's React islands | Joshua's pick; distinctive look for a showcase site |
| Content model | Hybrid — `posts` + `projects` in EmDash; resume + skills as JSON in-repo | Resume rarely changes and benefits from git diff; blog/projects benefit from admin UX |
| Language | JS where possible; TS for EmDash config + future plugins | Matches Joshua's comfort (JS) without fighting EmDash (TS) |
| Package manager | pnpm | Locked |
| Comments at launch | None; defer to native EmDash comments when they land | Dodges moderation + third-party embed cost |

## Architecture

**Stack:** Astro (SSR on Cloudflare) → EmDash (routes + admin + content) → D1 (content DB) + R2 (media).

**Content split:**
- EmDash-managed (Portable Text): `posts`, `projects`
- Repo-managed (JSON, imported at build): `src/data/resume.json`, `src/data/skills.json`

**Plugin posture:** `worker_loaders` enabled in `wrangler.jsonc` from day one even though no plugins ship at launch. Future plugins go in `/plugins/`, capability-scoped, TypeScript.

## Routes

| Route | Source | Notes |
|---|---|---|
| `/` | custom Astro page | Hero + section links + recent posts strip |
| `/resume` | `resume.json` + `skills.json` | Static, no CMS round-trip |
| `/blog` | EmDash `posts` | Index + search (Blog template) |
| `/blog/[slug]` | EmDash post | Detail |
| `/blog/tags/[tag]` | filtered `posts` | Tag-filtering logic ported from Portfolio template |
| `/projects` | EmDash `projects` | Grid (ported from Portfolio template) |
| `/projects/[slug]` | EmDash project | Case study (ported from Portfolio template) |
| `/projects/tags/[tag]` | filtered `projects` | Independent tag namespace from blog |
| `/blog/rss.xml` | feed | Per-type feed |
| `/projects/rss.xml` | feed | Per-type feed |
| `/admin/*` | EmDash | Untouched |

## RetroUI integration

- Add `@astrojs/react` + Tailwind to the Astro config (pick Tailwind v3 or v4 based on RetroUI's current requirement — verify during the spike)
- Run RetroUI's CLI per current docs; land components in `src/components/ui/`
- Astro pages import RetroUI React components, mount with `client:visible` or no directive (static render) where possible; `client:load` only for components that need JS on first paint
- RetroUI styling applies to chrome (hero, cards, buttons, skill chips, project cards). Blog post body typography stays readable, not retro-styled
- Fonts + CSS variables live in a root layout

## Known risks (carry into implementation plan)

1. **RetroUI + Astro + Tailwind compatibility.** RetroUI CLI assumes Next/Vite React shape. Paths and configs may not match an Astro project. First implementation task: spike — install stack, add one component, confirm it renders. If the CLI misbehaves, fall back to copy-pasting component source from docs.
2. **Portfolio feature porting.** "Port from Portfolio template" is cheap only if the grid/case-study code is self-contained. Read Portfolio template source before committing to port-vs-rewrite on each feature.
3. **EmDash admin UX unknown.** Joshua hasn't used it yet. If editing is painful he'll edit less. After first deploy, spend time in `/admin` and file upstream issues for friction.
4. **Template routing overrides.** Blog template likely owns `/`, `/rss.xml`, etc. Confirm overrides compose cleanly with EmDash's routing conventions instead of fighting them.

## Explicitly out of scope for v1

Comments, multi-user auth, analytics, search over `projects`, PDF export of resume, i18n, plugin development. None of these should be built speculatively.

## Next step

Hand off to the `writing-plans` skill to produce a concrete implementation plan (spike first, then scaffold + port + style).
