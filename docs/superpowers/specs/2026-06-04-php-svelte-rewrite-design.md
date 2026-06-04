# PHP + Svelte Rewrite — joshuaheidorn.com

**Date:** 2026-06-04
**Status:** Approved by Joshua, ready for implementation planning.
**Branch:** `php-svelte-rewrite`
**Supersedes:** `docs/plans/2026-04-23-personal-site-design.md` (Astro + EmDash + Cloudflare design)

## Goal

Rewrite the site so it runs on **PHP 8.2+ / MySQL on Joshua's shared host**, with **no Node runtime** (Node is build-time only, to compile Svelte islands and CSS). The site's **appearance and URLs are preserved**. EmDash, Cloudflare Workers, D1, and R2 are dropped entirely. **All** content — posts, projects, pages, resume, skills, site settings, menu, and media metadata — lives in MySQL and is editable through a small custom admin.

### Why this rewrite

The current stack (Astro SSR on Cloudflare Workers + EmDash CMS) ties the site to Cloudflare-specific bindings (D1/R2) and an Astro-native CMS whose admin is broken upstream. Joshua wants to drop runtime dependencies he has to operate and run on commodity PHP hosting he already pays for, which provides PHP + MySQL + SSH + Composer + CLI. Build-time Node is acceptable.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Runtime | PHP 8.2+ on shared host | No Node process to run; host already provides PHP + MySQL |
| Framework | Slim 4 + PDO | Lightweight routing/middleware/sessions without heaviness; PDO for MySQL |
| Templating | Twig (`slim/twig-view`) | Auto-escaping (XSS guard), clean partials/inheritance |
| Markdown | `league/commonmark` | Maintained, extensible (heading-anchor extension powers post TOC) |
| Interactive UI | Svelte 5 islands via Vite | Joshua's pick; compiled to static JS, no runtime framework server |
| Styling | Plain CSS ported verbatim; Tailwind/shadcn/RetroUI dropped | Visible look is 100% hand-written CSS + custom properties; zero Tailwind utility classes in markup |
| Fonts | Self-hosted `@font-face` | Removes 3rd-party runtime dependency; preserves preloading |
| Content store | MySQL, everything in DB | Joshua's pick: single editable source via the admin |
| Editor | Markdown textarea, stored as Markdown | Simple, portable, clean migration from Portable Text |
| Auth | Single user, PHP sessions | Only Joshua edits; keep it minimal but hardened |
| Migrations | Phinx | Standard, safe, CLI-runnable over SSH |
| Config | `phpdotenv` (`.env`, not committed) | Keep DB creds + app secret out of git |
| Tests | PHPUnit | Cover the risky logic (conversion, auth, slugs, queries) |
| PDF resume | Dompdf, on-demand, pure PHP | No headless Chrome / binary; renders from DB data |

## Architecture

Request flow: web server (Apache/Nginx) → `public/index.php` (Slim front controller) → route → controller → repository (PDO) → Twig render. Admin routes sit behind an auth middleware. `/api/search` returns JSON consumed by the Svelte search island.

### Project layout

```
public/
  index.php          # Slim front controller (the only PHP web entry)
  assets/            # Vite build output (hashed JS/CSS) + manifest.json
  uploads/           # user-uploaded media (served directly)
  fonts/             # self-hosted woff2
src/
  routes/            # route definitions: public.php, admin.php, api.php
  Controllers/       # thin controllers per area
  Repositories/      # PDO data access per content type
  Support/           # Markdown renderer, slugs, SEO meta, auth, asset-manifest helper
  views/             # Twig templates (public/ + admin/)
islands/
  Typewriter.svelte
  Search.svelte
  mount.ts           # hydrates [data-island] elements
db/
  migrations/        # Phinx migrations
bin/
  seed.php           # one-time content import from existing JSON/seed
tests/               # PHPUnit
.env                 # DB creds, app secret (gitignored)
phinx.php            # Phinx config
composer.json
package.json         # Vite + Svelte (build-time only)
vite.config.ts
```

The implementation should keep the tree shallow and avoid speculative abstractions (consistent with existing repo conventions: functional style, no abstractions until 3+ concrete usages).

## Data model (MySQL)

All tables use an auto-increment `id` (or ULID — implementer's choice, default auto-increment) and InnoDB.

- **`users`** — `email`, `password_hash`, `name`, `created_at`. Seeded with a single row (Joshua).
- **`posts`** — `slug` (unique), `title`, `excerpt`, `body_md`, `featured_image_id` (FK → media, nullable), `status` (`draft`|`published`), `published_at` (nullable), `created_at`, `updated_at`.
- **`projects`** — `slug` (unique), `title`, `summary`, `body_md`, `source_url` (nullable), `external_url` (nullable), `featured` (bool), `featured_image_id` (nullable), `status`, `published_at`, `created_at`, `updated_at`. *Current multi-section bodies are flattened to a single `body_md` using `##` headings.*
- **`pages`** — `slug` (unique), `title`, `body_md`, `status`, `published_at`, `created_at`, `updated_at`.
- **`terms`** — `taxonomy` (`tag`|`category`), `slug`, `label`. Unique on (`taxonomy`, `slug`).
- **`term_relationships`** — `term_id` (FK), `content_type` (`post`|`project`), `content_id`. Preserves the separate blog-tag / category / project-tag namespaces.
- **`media`** — `filename`, `path` (relative under `/uploads`), `alt`, `width`, `height`, `mime`, `created_at`.
- **Resume / skills (normalized):**
  - **`resume_meta`** (singleton row) — `name`, `headline`, `headlines` (JSON array for the typewriter), `summary`, `contact` (JSON: email, location, links[]).
  - **`experience`** — `role`, `company`, `location`, `start`, `end`, `bullets` (JSON), `tags` (JSON), `sort`.
  - **`education`** — `degree`, `school`, `location`, `start`, `end`, `sort`.
  - **`skill_categories`** — `name`, `sort`.
  - **`skills`** — `category_id` (FK), `name`, `sort`.
- **`settings`** — key/value: `site_title`, `site_tagline`, `site_logo`, `site_favicon`, social links.
- **`menu_items`** — `label`, `url`, `target`, `sort`.
- **`migrations`** — Phinx bookkeeping.

## Content migration (one-time `bin/seed.php`)

Reads the existing repo data and imports into MySQL. Idempotent where practical (truncate-and-reload acceptable for a one-time run).

- **Posts (8) + page (1):** read `seed/seed.json`; convert Portable Text blocks → Markdown. Blocks are simple (`_type: block`/`span`, normal/heading styles, lists, marks: strong/em/link/code). Insert rows; map `status` and `publishedAt`.
- **Projects (6):** read `src/data/projects.json`; join each `{heading, body}` section into one Markdown `body_md` (heading → `## heading`, body appended). Carry `summary`, `source_url`, `featured`, `tags`, `featured_image`, `publishedAt`.
- **Resume + skills:** read `resume.json` / `skills.json` into the normalized tables.
- **Media (~14 images):** copy `uploads/*` → `public/uploads/`; insert `media` rows (read dimensions/mime); rewrite `featured_image` references (and any in-body image refs) to the new media ids/paths.
- **Taxonomies / menu / settings:** from `seed.json` (`taxonomies`, `menus.primary`, `settings`) into `terms`, `menu_items`, `settings`.

A small **Portable Text → Markdown converter** in `src/Support/` (with PHPUnit coverage) does the body conversion; `bin/seed.php` orchestrates.

## Frontend (appearance preserved)

- Port `src/styles/theme.css` (palette + dark mode) and `Base.astro`'s `@layer base` reset/typography into static CSS files.
- Port each page/component scoped `<style>` block into CSS partials. Class names are already semantic and effectively unique (`article-grid`, `posts-grid`, `strip-item`, etc.); verify no collisions when scoping is removed.
- `Base.astro` → Twig `layout.twig`: identical header/nav/footer/theme-switcher markup.
- Ported as small **vanilla JS** (no framework needed): theme switcher, anti-flash inline script, ⌘K-to-focus-search, post-page TOC builder + scroll-spy.
- **Self-hosted fonts:** Space Grotesk (`--font-sans`), Archivo Black (`--font-head`), JetBrains Mono (`--font-mono`) via `@font-face`, preloaded.

### Svelte islands

Mounted on `[data-island="..."]` elements by `mount.ts`; only the two genuinely-interactive pieces:

- **`Typewriter`** — home-hero rotating headline (port of `TypewriterHeadline.tsx`); respects `prefers-reduced-motion`.
- **`Search`** — replaces EmDash `LiveSearch`. Calls `GET /api/search?q=` (SQL `LIKE`/FULLTEXT over published posts/projects/pages, returns title/type/snippet/url JSON) and renders the existing dropdown markup/classes.

### Dropped EmDash-only features

Comments (already deferred / out of scope), widget areas, the broken admin, and the plugin bridge. Footer "widget" slots become settings-driven static content.

## Admin (`/admin`, single user)

- Session login via `password_hash`/`password_verify`; CSRF token on every form; auth middleware on the `/admin` group; basic login rate-limiting; hardened session cookie (HttpOnly, Secure, SameSite).
- **CRUD** for posts / projects / pages: list + create/edit form — title, auto-slug from title (editable), Markdown `<textarea>` body, excerpt/summary, status (draft/published), `published_at`, `featured` toggle (projects), tag/category pickers, featured-image picker.
- **Media library:** upload to `public/uploads/`, record dimensions/mime/alt; pick/insert into content.
- **Resume / skills editors:** repeatable-row forms for `experience` / `education` / `skill_categories` + `skills`; singleton form for name/headline/headlines/summary/contact.
- **Settings + menu editors.**

## PDF resume export

- Route **`GET /resume.pdf`** streams a generated PDF (`Content-Disposition: attachment`).
- Generated **on demand in pure PHP via Dompdf** (no headless Chrome, no host binary), pulling the same resume/skills data from the DB.
- Renders a dedicated **print-optimized Twig template** (`resume_pdf.twig`) — a clean, readable, ATS-friendly layout. **Expectation:** this is *not* a pixel-match of the NeoBrutalism web look (PDF CSS engines support only a subset of CSS, and heavy offset-shadow styling prints poorly). It uses the same content and a tasteful subset of the palette/typography.
- PHPUnit smoke test: route returns a valid PDF (`%PDF-` header, non-trivial length).

## Routes (URLs preserved)

`/`, `/resume`, `/resume.pdf`, `/posts`, `/posts/{slug}`, `/posts/rss.xml`, `/projects`, `/projects/{slug}`, `/projects/tags/{slug}`, `/projects/rss.xml`, `/pages/{slug}`, `/tag/{slug}`, `/category/{slug}`, `/search`, `/api/search`, `/sitemap.xml`, `/404`, `/admin/*`. The old `/_emdash/*` routes are removed.

## SEO / feeds

- Twig `head` partial reproduces title/description/canonical/robots/OpenGraph/Twitter tags plus article `published`/`modified` meta (mirrors the current `EmDashHead` output).
- `/sitemap.xml`, `/posts/rss.xml`, `/projects/rss.xml` generated in PHP from the DB.

## Build & deploy (SSH + Composer + CLI)

- **Build (local, Node):** `npm run build` → Vite compiles Svelte islands + CSS into `public/assets/` with `manifest.json`; a PHP asset-manifest helper reads it to emit hashed `<link>`/`<script>` tags.
- **PHP deps:** `composer install`.
- **Deploy:** git pull (or rsync) to host → `composer install --no-dev` → `php vendor/bin/phinx migrate`. `.env` (not committed) holds DB creds + app secret.
- **One-time:** `php bin/seed.php` against the new DB to import existing content + media.

## Testing

PHPUnit covering the risky logic:
- Portable Text → Markdown converter
- Markdown → HTML rendering (incl. heading anchors)
- Slug generation / uniqueness
- Auth (hash/verify) + CSRF token validation
- Repository queries (against a disposable test DB)
- `/resume.pdf` smoke test (valid PDF output)

Svelte islands get light manual verification (optionally a couple of Vitest tests). This adds PHPUnit as a test runner (approved).

## Risks

1. **Admin scope** — "everything in DB/admin," especially the resume/skills repeatable-row forms, is the bulk of the build. Fallback if it bloats v1: edit resume/skills as a validated JSON document in a textarea (same tables, simpler UI).
2. **Portable Text → Markdown fidelity** — content is simple, but each of the 8 posts must be eyeballed after conversion.
3. **CSS port collisions** — Astro scoped styles become global; verify no class clashes.
4. **Auth security** — single user, but still: bcrypt/argon2, CSRF, session hardening, login rate-limit.
5. **No edge/SSR cache** — Astro used Cloudflare cache hints; on shared hosting add HTTP cache headers and an optional simple page cache for anonymous traffic.
6. **PDF fidelity** — accepted: print-optimized, not a web-look match.

## Out of scope (v1)

Comments, multi-user auth, analytics, i18n, plugins.

## Suggested implementation phasing (for the plan)

1. **Scaffold** — Slim + Twig + PDO + Vite/Svelte skeleton; `.env`/config; asset manifest; one "hello" route + one island rendering.
2. **Schema + migrations** — all tables via Phinx.
3. **Migration importer** — `bin/seed.php` + Portable Text→Markdown converter (+ tests); load real content/media into a local DB.
4. **Public site** — port layout + all public routes/templates/CSS; Typewriter + Search islands; RSS/sitemap/SEO; 404.
5. **Admin** — auth, CRUD for posts/projects/pages, media library, resume/skills/settings/menu editors.
6. **PDF export** — Dompdf + print template + route.
7. **Polish + deploy** — cache headers, hardening, deploy to host, run importer, verify.
