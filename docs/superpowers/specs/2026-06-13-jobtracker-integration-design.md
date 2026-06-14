# Job Tracker Integration — Design

**Date:** 2026-06-13
**Branch:** `php-svelte-rewrite`
**Status:** Approved (design); pending spec review → implementation plan

## Goal

Fold the standalone **Job Tracker** app (previously deployed at
`public/jobs` / `jobs.joshuaheidorn.com`, source at `/Volumes/Storage/Code/jobtracker`)
into the main site as a Clerk-gated `/jobs` section. The standalone deployment
(its own PHP front controller + SQLite + HTTP Basic Auth) is retired; the tracker
becomes part of the Slim app, sharing the site's MySQL database, Clerk login, and
build pipeline.

Context: the standalone deployment and its SQLite data were lost in a deploy
incident (no backup), so there is **no data to migrate** — the integrated tracker
starts empty. That simplifies the port to a pure code/schema move.

## Decisions (from brainstorming)

- **Home:** a **separate top-level `/jobs` section**, decoupled from `/admin`, behind
  the same Clerk login. Closest to how it ran as a standalone subdomain.
- **Look:** **reskin to NeoBrutalism** using the site's existing design tokens
  (`public/css/theme.css`: `--color-accent: #ffdb33`, black borders, yellow offset
  `--color-shadow`, Archivo Black headings). The accent-color picker is removed.
- **Dark mode:** reuse the site's **existing cookie-based light/dark/system theme
  switcher** (`:root.dark` tokens in `theme.css`, the FOUC-prevention script + the
  switcher in `layout.twig`) rather than a tracker-local toggle. The tracker's `theme`
  setting is therefore dropped.
- **Settings:** the only surviving tracker setting is the **stale-days threshold**.

## Architecture

Reuse the working Svelte 5 SPA; port its PDO backend into the Slim app against
MySQL. The standalone front controller, `serve.php`, and Basic Auth are dropped.

### 1. Data model (MySQL + Phinx)

A new Phinx migration (`db/migrations/`) creates:

- **`jobs`** — mirrors the SQLite schema:
  - `id` (PK, auto-increment), `company` (VARCHAR, NOT NULL), `role`, `link`,
    `salary`, `location` (VARCHAR, default ''), `description`, `notes` (TEXT,
    default ''), `status` (VARCHAR, NOT NULL), `added` (DATE), `updated` (DATE).
  - The SQLite `added`/`updated` were `TEXT` `YYYY-MM-DD`; they become real `DATE`
    columns. The server owns them: `added` set once on create; `updated` refreshed
    only when `status` changes ("status change resets the clock").
- **`job_settings`** — a single-row table (`id` CHECK = 1) holding
  `stale_days` (INT, default 14). A dedicated table (not a key in the site's
  existing `settings`) keeps the tracker's storage isolated and self-describing.

Status set unchanged: `saved`, `applied`, `submitted`, `interviewed`, `offer`,
`rejected`, `ghosted`.

### 2. Backend / API

- **Repositories** (`app/Repositories/`): port `JobRepository` and
  `SettingsRepository` from `jobtracker/backend/src/` into `App\Repositories\`.
  They are already PDO; adapt SQL to MySQL (e.g. `lastInsertId`, `DATE` handling)
  and follow the site convention of **column-whitelisted writes** (no
  mass-assignment). Keep the server-owned date logic and the `stale_days` clamp
  (3–90). Validation stays via a small exception type surfaced as a JSON 4xx.
- **Controller** (`app/Controllers/`): a thin `JobController` exposing JSON:
  - `GET /jobs/api/jobs` — list
  - `POST /jobs/api/jobs` — create (company + status required)
  - `PUT /jobs/api/jobs/{id}` — update
  - `DELETE /jobs/api/jobs/{id}` — delete
  - `GET /jobs/api/settings` — `{stale_days}`
  - `PUT /jobs/api/settings` — partial `{stale_days}` (clamped 3–90)
  - `GET /jobs` — renders the Twig shell that mounts the SPA island.
- All responses JSON (writes return the saved record), matching the tracker's
  current API contract so the frontend `api.js` changes are minimal.

### 3. Auth

The entire `/jobs` route group (the page **and** the API) is registered behind the
existing Clerk `AuthMiddleware`. Signed-out requests bounce to `/admin/login`
(the single Clerk sign-in). The SPA's `fetch` calls carry the same-origin Clerk
`__session` cookie, which `ClerkAuth::isAdmin()` verifies (single-admin allowlist).

**CSRF:** the JSON API is exercised only by same-origin XHR with
`Content-Type: application/json`, which forces a CORS preflight for any
cross-origin caller; combined with Clerk's `authorizedParties` check and the
`SameSite=Lax` session cookie, this is sufficient — these endpoints do **not** use
the form-CSRF token that the admin HTML forms use. (Stated explicitly so the
deviation from the admin forms is intentional, not an oversight.)

### 4. Frontend (reuse + reskin)

- **Island entry:** add a third Vite entry `islands/jobs.js` (alongside
  `main`/`admin`) that mounts the tracker's `App.svelte` via the shared
  `mountIslands` helper. The `/jobs` Twig shell is a thin authed page: the site's
  theme switcher + a `<div data-island="JobTracker">` + the `islands/jobs.js`
  bundle (its CSS/JS emitted through the Vite manifest, loaded only on `/jobs`).
- **Reuse unchanged:** `App.svelte`, `JobTable`, `JobModal`, `StatCards`,
  `StatusDonut`, `TimelineBars`, `Funnel`, `Header` (minus the accent picker),
  `state.svelte.js`, `compute.js`, `statuses.js`.
- **Reskin:** rewrite `styles/global.css` against the site's `theme.css` variables
  (NeoBrutalism: yellow/black, offset shadows, Archivo Black). Charts use the
  accent/border/shadow tokens. Remove the accent-picker UI and its state.
- **Dark mode:** no tracker-local toggle — the components consume the site's CSS
  variables, which flip under the site's `:root.dark` class. The theme switcher in
  the `/jobs` shell drives it (same cookie/script as the rest of the site).
- **Settings UI:** the settings popover reduces to the single stale-days control.
- **API base:** repoint `api.js` from relative `api` to absolute `/jobs/api`.
- **Caching:** add `/jobs` to the public-cache middleware's exclusion list (it is
  authed and must never be cached at the edge or shared).

### 5. Build pipeline

`vite.config.js` gains the `islands/jobs.js` input (now three entries:
`main`, `admin`, `jobs`). `deploy.sh` already excludes `public/jobs` from
`rsync --delete` (the old standalone app) — that exclude is now obsolete for the
old app but harmless; the integrated tracker ships as a normal hashed bundle in
`public/assets/` and a `/jobs` route, so no special deploy handling is needed.

## Error handling

- Validation failures (missing company, bad status, out-of-range `stale_days`) →
  JSON 4xx with the server's message (the SPA surfaces it).
- Unauthenticated `/jobs` or `/jobs/api/*` → 302 to `/admin/login` (page) or a
  denied response (API), via the Clerk middleware (fail-closed).
- Unknown job id on PUT/DELETE → 404 JSON.

## Testing

- **PHPUnit:** unit tests for `JobRepository` (CRUD, the status-change clock reset,
  date ownership) and `SettingsRepository` (clamp), against the test MySQL DB —
  adapting the tracker's existing `backend/tests/` integration coverage. An HTTP
  test mirroring `AdminAuthTest`: signed-out `/jobs` and `/jobs/api/jobs` are
  gated (302 / denied).
- **Vitest:** an island-mount test for `islands/jobs.js` (like
  `mount-islands.test.js`) — fallback removal + the SPA renders.
- **Manual E2E:** behind Clerk, exercise add/edit/delete, status-clock, stale flag,
  charts, and the stale-days setting; confirm `/jobs` is unreachable signed-out.

## Out of scope

- Migrating old job data (lost; starts empty).
- Multi-user / per-user data (single admin, same as the rest of `/admin`).
- The accent-color picker and per-app theme storage (dropped — site theme switcher
  handles dark mode).
- Publicly exposing the tracker (it stays behind Clerk).

## Open implementation-time checks

1. Exact MySQL column types/migration vs the Phinx default `id` (`int unsigned`)
   convention already used in the schema.
2. Whether the tracker's charts reference any colors that need explicit
   NeoBrutalism token mapping beyond accent/border/shadow.
3. The `/jobs` shell's interaction with the existing theme-switcher script (it
   lives in `layout.twig`; the `/jobs` page may use its own minimal shell, so the
   switcher markup + FOUC script must be included there too).
