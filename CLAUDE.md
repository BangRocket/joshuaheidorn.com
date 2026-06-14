# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Personal resume / portfolio / blog site for Joshua Heidorn (joshuaheidorn.com). Runs on **PHP + MySQL** on commodity shared hosting (no Node at runtime). Rebuilt from an Astro/EmDash/Cloudflare stack — design rationale and the phased implementation plans live in `docs/superpowers/specs/2026-06-04-php-svelte-rewrite-design.md` and `docs/superpowers/plans/2026-06-04-php-svelte-rewrite-*.md`. Read the spec before making architectural decisions.

## Stack

- **PHP 8.2+ / Slim 4** (routing/middleware) / **PDO** (MySQL) — the runtime.
- **Twig** (`slim/twig-view`) templates, auto-escaping on.
- **league/commonmark** — Markdown (post/project/page bodies) → HTML at request time.
- **dompdf/dompdf** — on-demand PDF resume at `/resume.pdf` (pure PHP, no headless browser).
- **Svelte 5 + Vite** — client islands (`Typewriter`, `Search` public; `AdminLogin`, `AdminUserButton` admin-only), compiled to hashed bundles in `public/assets/` (public `islands/main.js` + admin `islands/admin.js`, emitted as separate Vite entries). **Node is build-time only.**
- **Plain CSS** in `public/css/` (`theme.css` palette + `base.css` tokens + `layout.css`/`pages.css`/`article.css`). NeoBrutalism look (yellow `#ffdb33`, black borders, offset shadows, Archivo Black). No Tailwind.
- **Composer** (deps), **Phinx** (`db/migrations/`), **vlucas/phpdotenv** (`.env`), **PHPUnit** (PHP tests), **Vitest + jsdom** (Svelte island tests).
- **Clerk** (`clerkinc/backend-php` backend SDK + `svelte-clerk` client island) — admin identity provider. PHP verifies the Clerk `__session` cookie networklessly; sign-in/account UI is an admin-only Svelte island. Clerk JS loads only on `/admin` pages.
- **yarn (1.x classic)** for the JS build only (`yarn build`). Installed globally via `npm i -g yarn` (Node 26 has no Corepack).

## Local dev environment

- **MySQL 8 runs in Docker** — `docker compose up -d` (exposes `127.0.0.1:3306`, root / empty password, DBs `joshuaheidorn` + `joshuaheidorn_test`). Run the DB CLI as `docker compose exec -T db mysql -uroot ...`.
- **PHP 8.x + Composer are on the host** (Homebrew). The repo lives on an external `/Volumes/Storage` drive that Docker Desktop cannot bind-mount, so the PHP toolchain stays local.
- **Dev server:** `php -S 127.0.0.1:8088 -t public public/router.php` (after `yarn build`). `public/router.php` serves real static files and routes everything else through Slim.

## Architecture

- **Front controller:** `public/index.php` → `app/bootstrap.php` builds the Slim app (PDO, Twig, asset manifest, routes, 404 handler, public cache middleware) and returns it.
- **Routes:** `app/routes.php` — public routes plus a Clerk-guarded `/admin` group (`AuthMiddleware`).
- **Controllers** (`app/Controllers/`, admin under `app/Controllers/Admin/`) are thin; **repositories** (`app/Repositories/`) own all SQL via PDO; **support** utilities (`app/Support/`): `Database`, `Slug`, `Markdown`, `PortableText`, `ReadingTime`, `SiteIdentity`, `Seo`, `AssetManifest`, `Auth` (session bootstrap for CSRF), `ClerkAuth` (Clerk session verification + single-admin allowlist), `Csrf`, `Importer`.
- **Templates:** `app/views/` (public) + `app/views/admin/`. `layout.twig` owns the public chrome (nav, footer, theme switcher, search island, ⌘K). Assets are emitted via the Vite manifest helper (`assets.js()/assets.css()`).
- **Namespacing:** PSR-4 `App\` → `app/`, `Tests\` → `tests/`.

## Content model

All content lives in **MySQL** and is editable through the `/admin` UI:

- **Tables:** `posts`, `projects`, `pages` (Markdown bodies); `terms` + `term_relationships` (taxonomy: `tag`/`category`, polymorphic `content_type`); `media`; `resume_meta`/`experience`/`education`; `skill_categories`/`skills`; `settings`; `menu_items`; `users`.
- **Editing:** posts/projects/pages via CRUD forms (one generic `ContentController` + config); tags/categories inline (comma-separated, synced to `terms`); resume/skills/settings via validated **JSON-document** editors that rewrite the normalized tables.
- **Migration sources (kept in repo):** `seed/seed.json` (posts/pages/taxonomy/menu/settings), `src/data/{projects,resume,skills}.json` (+ `*.schema.json`), `uploads/*`. The one-time importer (`bin/seed.php` → `App\Support\Importer`) loads them, converting EmDash Portable Text → Markdown.

## Commands

- `composer install` — PHP deps
- `yarn install && yarn build` — compile Svelte islands → `public/assets/`
- `docker compose up -d` — start MySQL
- `vendor/bin/phinx migrate -e development` (and `-e testing`) — apply schema
- `php bin/seed.php` — import existing content into MySQL (destructive/idempotent; does not touch `users`)
- Admin user is created in the **Clerk dashboard** (restricted sign-ups + email allowlist); set `ADMIN_CLERK_USER_ID` in `.env` to the owner's Clerk user id.
- `vendor/bin/phpunit` — run PHP tests
- `yarn test` — run Svelte island tests (Vitest + jsdom; `islands/**/*.test.js`)
- Dev server: `php -S 127.0.0.1:8088 -t public public/router.php`

## Deploy (shared host: SSH + Composer + CLI)

Build locally (`yarn build`), then on the host: pull/rsync → `composer install --no-dev` → `vendor/bin/phinx migrate -e production`. `.env` (not committed) holds DB creds + Clerk keys (`CLERK_PUBLISHABLE_KEY`, `CLERK_SECRET_KEY`, `ADMIN_CLERK_USER_ID`, `APP_URL`). Web root points at `public/`; `public/.htaccess` rewrites to `index.php`. Upload `public/uploads/` + `public/assets/` (both gitignored).

## Conventions

- Functional, thin controllers; SQL only in repositories (column whitelists for writes — no mass-assignment).
- All mutating admin routes validate CSRF; uploads are MIME-whitelisted and filenames randomized.
- `git add .` is forbidden. Add files by name (the cleanup commit is the one exception, reviewed first).
- Keep the tree shallow; no abstractions until 3+ concrete usages (the generic content admin earns its keep at exactly 3: posts/projects/pages).
- Plain CSS only; reproduce the existing look — don't reintroduce Tailwind.

## Out of scope for v1

Comments, multi-user auth, analytics, i18n. Resume/skills repeatable-row form UIs (JSON editor is the current path). Clerk handles login brute-force/rate-limiting. Dropping the now-dormant `users` table is a deferred follow-up.
