# Personal Site Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship joshuaheidorn.com — an EmDash-powered Astro site on Cloudflare, Blog template as base with Portfolio template features ported in, restyled with RetroUI, resume+skills content loaded from repo JSON.

**Architecture:** Astro (SSR on Cloudflare Workers) runs EmDash CMS. `posts` and `projects` collections live in D1 (EmDash-managed). `resume.json` and `skills.json` live in `src/data/` and render at build time. RetroUI React components mount as Astro islands.

**Tech Stack:** Astro, EmDash CMS, Cloudflare Workers + D1 + R2, Wrangler, React (islands only), Tailwind CSS, RetroUI, pnpm, TypeScript (EmDash) + JavaScript (app code where possible).

**Design doc:** `docs/plans/2026-04-23-personal-site-design.md` — read for decision rationale.

**Testing posture:** No automated test runner at launch. Verification is manual: run `pnpm dev`, load the route in a browser, confirm expected output. Every task ends with a manual verification step and a commit.

---

## Phase 0 — Pre-flight spike

### Task 1: RetroUI + Astro compatibility spike (throwaway)

**Purpose:** Before committing to the whole EmDash + RetroUI pairing, verify RetroUI's CLI works in an Astro+React project. If it fails, we adjust (fall back to manually copying component source from the RetroUI docs). This is a throwaway — spike lives in a scratch dir, not the main repo.

**Files:**
- Create: `~/tmp/retroui-astro-spike/` (outside the project repo)

**Step 1: Scaffold a minimal Astro+React project**

```bash
cd ~/tmp && pnpm create astro@latest retroui-astro-spike -- --template minimal --typescript strict --no-git --install
cd retroui-astro-spike
pnpm astro add react
pnpm astro add tailwind
```

Expected: Astro dev server starts with `pnpm dev` at `http://localhost:4321`.

**Step 2: Open RetroUI docs in a browser and follow their current Vite-React install steps**

The RetroUI docs site (https://www.retroui.dev/docs) does not render reliably via automated fetchers — **Joshua needs to open it in a browser** and follow the current install steps (they use a shadcn-style CLI that mutates config files).

**What to verify during the spike:**
- Does the CLI accept an Astro project or error out?
- Does it write to `src/components/ui/` or somewhere Astro-incompatible?
- Does it modify `tsconfig.json` with aliases Astro understands?
- Does the Tailwind config it generates match Astro's Tailwind integration (v3 vs v4)?

**Step 3: Add a single RetroUI component (e.g. Button) to an Astro page**

```astro
---
// src/pages/index.astro
import { Button } from "../components/ui/button";
---
<html>
  <body>
    <Button client:visible>Hello retro</Button>
  </body>
</html>
```

Expected: Page renders, button is styled with RetroUI's NeoBrutalism look.

**Step 4: Record findings**

Write a short note (in this session's chat, not a file) covering:
- Did RetroUI's CLI work in Astro? Yes/No/Partial.
- Tailwind version needed (v3 or v4) — confirms which `@astrojs/tailwind` integration / `@tailwindcss/vite` plugin applies.
- Any config files that needed manual fixup.
- Any components that broke with `client:visible` vs `client:load`.

**Step 5: Decide path forward**

Based on findings:
- **If CLI works cleanly:** proceed with Task 3's normal install flow.
- **If CLI breaks:** fallback is to copy RetroUI component source from their docs by hand into `src/components/ui/` — slower, still works. Adjust Task 5 accordingly.

**Step 6: Tear down the spike**

```bash
rm -rf ~/tmp/retroui-astro-spike
```

No commit. This task produces no code in the main repo, only learning.

---

## Phase 1 — Scaffold

### Task 2: Scaffold EmDash Blog template

**Files:**
- Entire repo gets populated (it's currently empty except `CLAUDE.md` + `docs/plans/`)

**Step 1: Check EmDash's current create command**

Browse https://github.com/emdash-cms/emdash README for the current init command. As of 2026-04 it's:

```bash
pnpm create emdash@latest
```

**Step 2: Run the scaffold into the project directory**

Because the directory has files (`CLAUDE.md`, `docs/`, `.git/`), scaffolding in-place may error. Use a temp dir + move strategy:

```bash
cd /tmp
pnpm create emdash@latest joshuaheidorn-scaffold
# When prompted, pick the Blog template
# Pick Cloudflare deploy target
# Pick TypeScript
cd joshuaheidorn-scaffold
rsync -av --exclude='.git' --exclude='CLAUDE.md' --exclude='docs' ./ /Volumes/Storage/Code/joshuaheidorn.com/
cd /Volumes/Storage/Code/joshuaheidorn.com
rm -rf /tmp/joshuaheidorn-scaffold
```

Expected: Astro + EmDash files now live in the project repo alongside existing `CLAUDE.md` and `docs/`.

**Step 3: Install deps**

```bash
pnpm install
```

Expected: Lockfile created, no errors.

**Step 4: Verify dev server starts**

```bash
pnpm dev
```

Expected: Astro dev server starts, EmDash admin renders at `/admin` (or wherever EmDash's README says). Home page renders the default Blog template.

**Step 5: Commit**

```bash
git add .gitignore package.json pnpm-lock.yaml astro.config.* wrangler.jsonc src/ public/ tsconfig.json
# Add any other top-level files the scaffold produced, individually — no `git add .`
git status  # review what's staged
git commit -m "scaffold EmDash blog template"
```

---

### Task 3: Confirm Cloudflare deploy wiring

**Files:**
- Modify: `wrangler.jsonc` (account ID, D1 binding, R2 bucket name)

**Step 1: Log into Cloudflare via wrangler**

```bash
pnpm wrangler login
```

Expected: Browser auth flow, returns "logged in as <your-account>".

**Step 2: Read `wrangler.jsonc` — note what's there**

EmDash's scaffold should have populated it. Confirm:
- `worker_loaders` block is present and **not** commented out (we want plugin sandbox enabled, even with no plugins yet)
- D1 binding declared
- R2 binding declared

**Step 3: Create D1 database and R2 bucket if scaffold didn't**

```bash
pnpm wrangler d1 create joshuaheidorn-content
pnpm wrangler r2 bucket create joshuaheidorn-media
```

Paste the returned `database_id` into `wrangler.jsonc`. Paste the R2 bucket name into the appropriate binding.

**Step 4: Run EmDash migrations**

Per EmDash README — likely:

```bash
pnpm wrangler d1 migrations apply joshuaheidorn-content --local
```

Expected: Migrations applied, schema tables created.

**Step 5: Dev server smoke test**

```bash
pnpm dev
```

Visit `/admin`. Expected: Admin UI loads without DB errors.

**Step 6: Commit**

```bash
git add wrangler.jsonc
git commit -m "wire cloudflare D1 + R2 bindings"
```

---

### Task 4: Add React + Tailwind integrations

Only needed if EmDash's scaffold doesn't already include these. Check `astro.config.*` first — EmDash's Blog template likely already has Tailwind. React may or may not be there.

**Files:**
- Modify: `astro.config.mjs` or `astro.config.ts`
- Modify: `package.json` (new deps)
- Possibly new: `tailwind.config.js` or global `app.css` with `@import "tailwindcss"` (v4)

**Step 1: Check current config**

Read `astro.config.*`. Note which integrations are already present.

**Step 2: Add missing integrations**

```bash
# Only if missing:
pnpm astro add react
# Only if missing and Tailwind v3:
pnpm astro add tailwind
# If v4 (no @astrojs/tailwind integration exists):
pnpm add -D tailwindcss @tailwindcss/vite
# + add @tailwindcss/vite plugin to astro.config.mjs's `vite.plugins` array
```

Which Tailwind flavor to use is determined by Task 1's spike findings. Do not guess.

**Step 3: Verify config parses**

```bash
pnpm dev
```

Expected: No config errors, dev server starts.

**Step 4: Commit**

```bash
git add astro.config.* package.json pnpm-lock.yaml tailwind.config.* src/styles/app.css
git commit -m "add react + tailwind integrations"
```

---

### Task 5: Install RetroUI and smoke-test one component

**Files:**
- Create: `src/components/ui/*.tsx` (or `.jsx` — depends on RetroUI CLI output)
- Possibly modify: `tsconfig.json` (path aliases), `tailwind.config.*`

**Step 1: Follow RetroUI install per Task 1's spike findings**

Open https://www.retroui.dev/docs in a browser. Run the current install steps.

**Step 2: Add one component (Button) as a smoke test**

Either via their CLI or by hand-copying source. Confirm it lands in `src/components/ui/button.tsx`.

**Step 3: Mount it on the Blog template's home page**

Pick an easy insertion point in the Blog template's layout (e.g., the header's "Home" link becomes a RetroUI Button).

**Step 4: Dev server smoke test**

```bash
pnpm dev
```

Visit `http://localhost:4321`. Expected: Button renders with retro styling. No hydration warnings in the console.

**Step 5: Commit**

```bash
git add src/components/ui/ tsconfig.json tailwind.config.*
git add <the blog layout file you modified>
git commit -m "install retroui + smoke-test button component"
```

---

## Phase 2 — Content model + data

### Task 6: Add `projects` collection to EmDash

**Files:**
- Modify: EmDash content schema (location depends on scaffold — commonly `src/content.config.ts` or EmDash's own `schema.ts`)
- Possibly new: `migrations/NNNN_add_projects.sql` if EmDash uses migration files

**Step 1: Locate EmDash's schema definition**

Search for where `posts` is defined:

```bash
grep -rn "posts" src/ --include="*.ts" --include="*.js" | head
```

**Step 2: Add a `projects` collection definition**

Mirror the `posts` schema structure but with project-appropriate fields (title, slug, summary, body PortableText, tags, heroImage, externalUrl, githubUrl, featured bool, date).

**Step 3: Generate + apply migration**

Per EmDash's conventions (check their README — may be `pnpm emdash migrate` or `wrangler d1 migrations create`):

```bash
pnpm wrangler d1 migrations create joshuaheidorn-content add_projects
# edit the SQL file
pnpm wrangler d1 migrations apply joshuaheidorn-content --local
```

**Step 4: Verify in admin**

```bash
pnpm dev
```

Visit `/admin`. Expected: "Projects" appears as a content type. Create a test project, confirm it persists.

**Step 5: Commit**

```bash
git add src/content.config.* migrations/
git commit -m "add projects collection"
```

---

### Task 7: Create resume + skills JSON

**Files:**
- Create: `src/data/resume.json`
- Create: `src/data/skills.json`

**Step 1: Draft `resume.json` schema + content**

Joshua provides actual data. Shape:

```json
{
  "name": "Joshua Heidorn",
  "headline": "...",
  "summary": "...",
  "contact": { "email": "...", "location": "...", "links": [{ "label": "GitHub", "url": "..." }] },
  "experience": [
    { "company": "...", "role": "...", "start": "2023-01", "end": "present", "bullets": ["..."], "tags": ["..."] }
  ],
  "education": [
    { "school": "...", "degree": "...", "year": "..." }
  ]
}
```

**Step 2: Draft `skills.json`**

```json
{
  "categories": [
    { "name": "Languages", "items": [{ "name": "Python", "proficiency": "expert" }, ...] },
    { "name": "Tooling", "items": [...] }
  ]
}
```

**Step 3: Import into a throwaway Astro page to verify JSON parses**

Create `src/pages/_debug-resume.astro`:

```astro
---
import resume from "../data/resume.json";
import skills from "../data/skills.json";
---
<pre>{JSON.stringify({ resume, skills }, null, 2)}</pre>
```

Visit `http://localhost:4321/_debug-resume`. Expected: Valid JSON dumps.

**Step 4: Delete the debug page**

```bash
rm src/pages/_debug-resume.astro
```

**Step 5: Commit**

```bash
git add src/data/resume.json src/data/skills.json
git commit -m "add resume + skills content data"
```

---

## Phase 3 — Layout + pages

### Task 8: Root layout with RetroUI theme + fonts

**Files:**
- Modify: `src/layouts/BaseLayout.astro` (or whatever the Blog template named it)
- Modify: `src/styles/app.css` (CSS variables, fonts)

**Step 1: Identify the template's root layout file**

```bash
ls src/layouts/
```

**Step 2: Replace the template's default styles with RetroUI theme tokens**

Consult RetroUI docs for the exact CSS variables / theme tokens. Likely includes:
- `--color-bg`, `--color-fg`, `--color-accent`
- A NeoBrutalism-friendly font (often Archivo Black + Inter pairing)
- Shadow/border tokens (`--shadow-brutal`, `--border-brutal`)

**Step 3: Import fonts**

In `src/styles/app.css` or via `<link>` in the layout. Prefer self-hosting (avoid Google Fonts runtime fetch).

**Step 4: Dev server check**

```bash
pnpm dev
```

Visit `/`. Expected: Home page shows the new fonts + color palette. Layout hasn't broken.

**Step 5: Commit**

```bash
git add src/layouts/BaseLayout.astro src/styles/app.css public/fonts/
git commit -m "apply retroui theme to root layout"
```

---

### Task 9: Home page (`/`)

**Files:**
- Modify: `src/pages/index.astro` (overrides the Blog template's default home)

**Step 1: Outline the sections**

- Hero block with name, headline, 1-sentence pitch
- Four big nav cards: Resume, Projects, Blog, Contact
- Recent posts strip (3 most recent from `posts`)
- Optional: featured projects strip (projects where `featured: true`)

**Step 2: Implement sections using RetroUI components**

Use RetroUI `Card`, `Button`, etc. for the nav cards and hero. Keep section spacing generous.

**Step 3: Wire recent posts**

Query EmDash's `posts` collection, sort by date desc, take 3. EmDash's data-fetching pattern is in their README.

**Step 4: Dev server check**

Visit `/`. Verify:
- Hero renders
- Nav cards link correctly (`/resume`, `/projects`, `/blog`, mailto or `/contact`)
- Recent posts show actual post titles + dates
- Layout holds up at mobile, tablet, desktop widths

**Step 5: Commit**

```bash
git add src/pages/index.astro src/components/
git commit -m "build custom home page"
```

---

### Task 10: Resume page (`/resume`)

**Files:**
- Create: `src/pages/resume.astro`

**Step 1: Import JSON and lay out experience + education + skills**

```astro
---
import resume from "../data/resume.json";
import skills from "../data/skills.json";
import BaseLayout from "../layouts/BaseLayout.astro";
// + RetroUI components
---
```

**Step 2: Render experience as RetroUI cards**

Each entry: company + role header, date range, bullets as a list. Tags as skill chips at the bottom of each entry.

**Step 3: Render skills section**

Grouped by category. Each skill is a RetroUI chip.

**Step 4: Add a "Download as PDF" placeholder (disabled)**

Out of scope for v1 per design doc. Leave a commented-out link to remind future you.

**Step 5: Dev server check**

Visit `/resume`. Expected: All JSON content renders, styling is cohesive with home, no console errors.

**Step 6: Commit**

```bash
git add src/pages/resume.astro
git commit -m "build resume page from JSON"
```

---

### Task 11: Port Portfolio template's project grid → `/projects`

**Files:**
- Create: `src/pages/projects/index.astro`
- Possibly new: `src/components/ProjectCard.astro`

**Step 1: Read the Portfolio template's source**

Clone it to a scratch directory:

```bash
cd /tmp && pnpm create emdash@latest portfolio-ref
# pick Portfolio template
```

Read `src/pages/index.astro` (or wherever the grid lives) and the card component. Understand what's self-contained and what's tangled with the Portfolio template's layout.

**Step 2: Port the grid logic**

Write `src/pages/projects/index.astro` in the main repo. Adapt to:
- Query `projects` collection (not Portfolio's default)
- Use our `BaseLayout`, not Portfolio's
- Use RetroUI card styling on each project tile

**Step 3: Port/rewrite the ProjectCard component**

If the Portfolio template's card is simple, port. If it's heavily styled with Portfolio's design tokens, rewrite from scratch using RetroUI.

**Step 4: Dev server check**

Visit `/projects`. Expected: Grid shows seeded projects with cover images, titles, summaries.

**Step 5: Tear down reference repo**

```bash
rm -rf /tmp/portfolio-ref
```

**Step 6: Commit**

```bash
git add src/pages/projects/index.astro src/components/ProjectCard.astro
git commit -m "port portfolio project grid"
```

---

### Task 12: Case study pages → `/projects/[slug]`

**Files:**
- Create: `src/pages/projects/[slug].astro`

**Step 1: Reference the Portfolio template's case study route**

Re-clone or re-inspect. Port the layout pattern (hero image, summary, PortableText body, sidebar with tags/links).

**Step 2: Implement in main repo**

Fetch the project by slug from EmDash, render PortableText via EmDash's renderer, use RetroUI for chrome.

**Step 3: Dev server check**

Create a seed project via `/admin`, visit `/projects/<that-slug>`. Expected: Full case study page renders.

**Step 4: Commit**

```bash
git add src/pages/projects/[slug].astro
git commit -m "port portfolio case study page"
```

---

### Task 13: Tag filtering for posts and projects

**Files:**
- Create: `src/pages/blog/tags/[tag].astro`
- Create: `src/pages/projects/tags/[tag].astro`
- Possibly shared: `src/lib/tags.ts` (small util)

**Step 1: Write the tag filter util**

A simple function that takes a collection + a tag and returns entries where `tags.includes(tag)`. Shared between both routes.

**Step 2: Implement `blog/tags/[tag].astro`**

`getStaticPaths` (or SSR equivalent) enumerates all tags across posts. The route renders a filtered list using the same post-list component as `/blog`.

**Step 3: Implement `projects/tags/[tag].astro`**

Mirror structure. Uses the project-grid component from Task 11.

**Step 4: Add tag links in post and project cards**

On the list/card views, tags render as clickable RetroUI chips linking to `/blog/tags/<tag>` or `/projects/tags/<tag>`.

**Step 5: Dev server check**

Create posts/projects with overlapping + distinct tags. Visit `/blog/tags/<tag>` and `/projects/tags/<tag>`. Expected: Only matching entries appear.

**Step 6: Commit**

```bash
git add src/pages/blog/tags/ src/pages/projects/tags/ src/lib/tags.ts src/components/
git commit -m "add tag filtering for posts and projects"
```

---

### Task 14: Split RSS feeds

**Files:**
- Modify or create: `src/pages/blog/rss.xml.ts` (or `.js`)
- Create: `src/pages/projects/rss.xml.ts`
- Possibly delete: existing `src/pages/rss.xml.*` if Blog template put it at root

**Step 1: Check existing RSS**

Blog template likely has `src/pages/rss.xml.ts`. Move it to `src/pages/blog/rss.xml.ts`.

**Step 2: Write `projects/rss.xml.ts`**

Use Astro's `@astrojs/rss` package. Emit projects sorted by date desc with `link`, `title`, `description`, `pubDate`.

**Step 3: Update any links that referenced `/rss.xml`**

Probably in the root layout footer.

**Step 4: Dev server check**

Visit `/blog/rss.xml` and `/projects/rss.xml`. Expected: Valid XML in both, each with the right content type.

**Step 5: Commit**

```bash
git add src/pages/blog/rss.xml.* src/pages/projects/rss.xml.* src/layouts/
git commit -m "split RSS into separate blog + projects feeds"
```

---

## Phase 4 — Deploy

### Task 15: First production deploy

**Files:** None. Cloudflare side work.

**Step 1: Apply migrations to production D1**

```bash
pnpm wrangler d1 migrations apply joshuaheidorn-content --remote
```

Expected: Migrations applied to the production DB.

**Step 2: Deploy**

```bash
pnpm wrangler deploy
```

Expected: Worker deployed, returns a `*.workers.dev` URL.

**Step 3: Smoke-test the `*.workers.dev` URL**

Visit every route from the design doc's route table. Expected: All render. Record any that break.

**Step 4: Fix blockers if any**

If a route 500s, fix before moving on. Common issues: environment variables not set, D1/R2 bindings mis-named between local and remote.

---

### Task 16: Point joshuaheidorn.com at the Worker

**Files:** None.

**Step 1: In Cloudflare dashboard**, add a custom domain to the Worker: `joshuaheidorn.com` and `www.joshuaheidorn.com`.

**Step 2: Verify DNS**

Should be auto-wired if the domain is on Cloudflare DNS. If not, add CNAME records as instructed.

**Step 3: Smoke-test `https://joshuaheidorn.com`**

Expected: Site loads over HTTPS. SSL valid.

---

### Task 17: Post-deploy content seeding

**Files:** Content in EmDash admin.

**Step 1: Create 2–3 real projects via `/admin`**

Fill tags, summaries, hero images. Confirm they appear at `/projects`.

**Step 2: Write the launch blog post**

"Site is live" or similar. Confirm it appears at `/blog`.

**Step 3: Log admin UX friction**

Open a local file `docs/emdash-feedback.md` (not committed until there's something to say) with anything painful about the authoring experience. This feeds the "fill gaps later" posture from the design doc.

**Step 4: Final smoke**

Visit the site as a logged-out user (incognito). Verify:
- Home page loads
- Resume page loads with current JSON content
- Projects grid populated
- Blog index populated
- RSS feeds valid
- 404s where expected (random URLs)

**Step 5: Commit anything left**

```bash
git status
# only commit if there are meaningful uncommitted changes
```

---

## Out of scope (do not build)

Per design doc: comments, multi-user auth, analytics, search over projects, PDF export of resume, i18n, plugin development. If scope creep tempts you, open a TODO file — don't implement.

---

**Plan complete and saved to `docs/plans/2026-04-23-personal-site-implementation.md`. Two execution options:**

**1. Subagent-Driven (this session)** — I dispatch fresh subagent per task, review between tasks, fast iteration.

**2. Parallel Session (separate)** — Open new session with executing-plans, batch execution with checkpoints.

**Which approach?**
