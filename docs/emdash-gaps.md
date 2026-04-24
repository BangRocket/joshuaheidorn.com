# EmDash Gaps

Running log of upstream EmDash / ecosystem bugs we've hit, with workarounds. Per the design doc's "fill gaps later" posture, these are candidates for plugin work, patches, or issues upstream.

## 🔴 `projects` publish fails with `SQLITE_CORRUPT_VTAB` on remote D1 — collection retired

**Symptom:** Publishing any entry in the `projects` collection via `/_emdash/api/content/projects/:id/publish` returns HTTP 500. Worker log: `D1_ERROR: database disk image is malformed: SQLITE_CORRUPT (extended: SQLITE_CORRUPT_VTAB)`. Posts and pages publish cleanly.

**Root cause:** Not conclusively identified. Characterized on 2026-04-24:

- FTS5 `integrity-check` on all three `_emdash_fts_*` tables passes.
- Rebuilt `_emdash_fts_projects` index from scratch (DROP + CREATE + `INSERT INTO fts(fts) VALUES('rebuild')`). No effect.
- Bug reproduces via plain CLI `UPDATE ec_projects SET <fts-indexed-col> = <any-non-NULL>` (e.g., `summary = 'x'`). Same UPDATE on `ec_posts`/`ec_pages` works. Same UPDATE on `ec_projects.updated_at` (non-FTS col) works. Same UPDATE on `ec_projects.title` with a value change works.
- Reducing `_emdash_fts_projects` to 2 indexed columns (matching posts/pages shape) did **not** resolve — value-change UPDATEs on `content` still fail.
- Appears to be a D1 storage-layer issue specific to this `ec_projects` table that FTS layer operations can't reach. Not reproducible by hand on a fresh table.

**Resolution (2026-04-24):** Retired the `projects` EmDash collection. Projects are now repo-local JSON at `src/data/projects.json` (mirrors the `resume.json` / `skills.json` pattern). Routes under `src/pages/projects/*` read from JSON instead of `getEmDashCollection("projects")`. Loses CMS-backed authoring, taxonomy-backed tag filtering, and cross-collection search. Keeps `/projects` landing, `/projects/<slug>` case study, `/projects/tags/<tag>`, RSS — all from JSON.

**Leftover cleanup (optional, does not block anything):**

- `ec_projects`, `_emdash_fts_projects` (+ 4 shadow tables), 3 triggers, and projects-collection revisions remain in both remote D1 and local miniflare D1. Inert — no code queries them.
- `seed/seed.json` may still reference a `projects` collection. Re-seeding won't affect runtime since no route reads it.
- EmDash auto-generates `Project` in `emdash-env.d.ts`; will self-update on next `yarn dev` once the collection is dropped.

**If upstream fixes the underlying D1 / FTS5 issue**, we can re-adopt EmDash projects by reversing these steps. Filed on the `projects` side only — posts/pages remain on EmDash.

## 🔴 Admin UI crashes with React error #300 (blocking)

**Symptom:** Opening `/_emdash/admin/*` in the browser produces `Error: Minified React error #300` (element type is invalid). The React island never hydrates; admin is unusable end-to-end. Affects login/setup/content pages.

**Root cause:** `@cloudflare/kumo`'s `Sidebar` named export is lost through Vite's esbuild pre-bundler in dev and through Rollup in prod. `@emdash-cms/admin/src/components/Sidebar.tsx` imports `Sidebar` from `@cloudflare/kumo` and gets `undefined` at runtime, which React surfaces as error #300 during hydration.

**Upstream:** [emdash-cms/emdash#469](https://github.com/emdash-cms/emdash/issues/469) — open, no comments or fixes as of 2026-04-23. Affects kumo ≥1.16.0 (maybe earlier).

**Tried and didn't help:**
- `vite.optimizeDeps.exclude: ["@cloudflare/kumo"]`
- `vite.ssr.noExternal: ["@cloudflare/kumo"]`
- Upgrading to emdash 0.7.0 (still pins kumo ^1.16.0)

**Workaround until upstream fixes it:**
Author content as seed data and deploy it, instead of using the admin.

1. Edit `seed/seed.json` to add or modify content entries (posts, projects, etc.).
2. Apply to local data.db: `yarn seed`
3. Also apply to miniflare's local D1 used in `yarn dev`:
   ```
   yarn emdash init -d .wrangler/state/v3/d1/miniflare-D1DatabaseObject/<hash>.sqlite --force
   yarn emdash seed -d .wrangler/state/v3/d1/miniflare-D1DatabaseObject/<hash>.sqlite
   ```
4. To push to prod: dump local miniflare sqlite, strip `_cf_METADATA` / FTS shadow-table INSERTs / `sqlite_schema` hacks, apply via `yarn wrangler d1 execute DB --remote --file=<dump>`. (See the script in this repo's history for exact logic — commit `59143b4`.)

**What to do if you pick this up:**
- Add a comment on upstream issue #469 with our repro details.
- Try a newer `@cloudflare/kumo` (>1.19.0 once published) via yarn `resolutions`.
- Or binary-search older kumo versions to find one that bundles cleanly and pin via `resolutions`.

## 🟡 blog-cloudflare template ships without tiptap/yjs peer deps (minor)

**Symptom:** `yarn dev` crashes on first request with `Could not resolve "@tiptap/extension-collaboration"`, then `yjs`, then `y-protocols`.

**Root cause:** The blog-cloudflare template's `package.json` doesn't declare these as direct deps, but EmDash admin's editor pulls them in transitively in a way Vite can't resolve without the top-level entry.

**Workaround (applied locally):** Added to `package.json` — `@tiptap/extension-collaboration`, `@tiptap/y-tiptap`, `yjs`, `y-protocols`. See commit `ab92529`.

**Upstream:** not yet filed.

## 🟡 blog-cloudflare template omits `emdash.seed` pointer (minor)

**Symptom:** `yarn bootstrap` succeeds on `emdash init` but errors on `emdash seed` with "No seed file found" even though `seed/seed.json` ships with the template.

**Root cause:** EmDash CLI looks at `.emdash/seed.json` or `emdash.seed` in `package.json`. Template provides neither.

**Workaround (applied locally):** Added `"emdash": { "seed": "./seed/seed.json" }` to `package.json`. See commit `ab92529`.

**Upstream:** not yet filed.

## 🟢 Remote D1 schema needs manual seeding

**Symptom:** Fresh worker deploy auto-creates some tables on first request but leaves `_emdash_collections` empty. Admin then 404s with "Collection X not found", even though the public site code tolerates it.

**Workaround (applied once, commit `59143b4`):**
Dump local miniflare sqlite → strip D1-incompatible statements (`_cf_METADATA` CREATE/INSERT, `sqlite_schema` VTABLE hacks, FTS shadow-table INSERTs) → apply to remote via `wrangler d1 execute --remote --file=`. FTS virtual tables need their `CREATE VIRTUAL TABLE` SQL reconstructed from `sqlite_schema.sql` values.

**Upstream:** not yet filed. This is arguably a missing feature (first-deploy remote migration) rather than a bug.
