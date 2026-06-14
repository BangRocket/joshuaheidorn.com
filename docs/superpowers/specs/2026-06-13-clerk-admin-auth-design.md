# Clerk Admin Auth — Design

**Date:** 2026-06-13
**Branch:** `php-svelte-rewrite`
**Status:** Approved (design); pending spec review → implementation plan

## Goal

Replace the bespoke PHP password/session login for the `/admin` panel with
[Clerk](https://clerk.com). Clerk becomes the single identity provider so future
authenticated features on the site can reuse it. The immediate driver is a more
secure, lower-maintenance login for the **single admin user** (the site owner) —
there are no public/multi-user accounts in scope.

## Why Clerk here (and the tension we accept)

This project is deliberately **pure PHP on commodity shared hosting with no Node
at runtime** (Svelte is build-time only; CLAUDE.md). Clerk is a JS-first hosted
auth platform, so adopting it cuts against that grain in two ways we explicitly
accept:

- Clerk's sign-in UI runs as browser JavaScript, so the admin login page gains a
  **runtime JS dependency** (loaded only on admin pages, never on the public site).
- We take on a **paid third-party SaaS dependency** and a network dependency at
  login time.

These are accepted trade-offs. They are mitigated by:

- An **official PHP backend SDK** (`clerk/clerk-sdk-php`) that verifies Clerk
  session JWTs **networklessly** (JWKS, cached) — no custom crypto glue.
- A **community Svelte 5 SDK** (`wobsoriano/svelte-clerk`) whose *client* entry
  (`svelte-clerk/client`) drops cleanly into this project's existing
  build-time-compiled island pattern. We use only its client side; its
  SvelteKit-server entry (`svelte-clerk/server`) is **not** used.

## Architecture

Clerk owns identity. The split:

- **Frontend (browser):** `svelte-clerk/client` components mounted as a new
  **admin-only** Svelte island. Provides the sign-in UI and the account/sign-out
  control, and keeps Clerk's short-lived session cookie refreshed while admin
  pages are open.
- **Backend (PHP):** `clerk/clerk-sdk-php` verifies the Clerk `__session` cookie
  on every `/admin/*` request via networkless JWT verification. This replaces the
  PHP session check.

Session transport is the **`__session` cookie** (same-origin, set by Clerk JS,
sent automatically on server-rendered admin navigations) — the natural fit for
this server-rendered Twig admin. No bearer-token/SPA handoff.

### Components & data flow

1. **Login island** — `/admin/login` is still a server-rendered Twig page, but it
   mounts a Svelte island wrapping `<ClerkProvider publishableKey={…}>` around
   `<SignIn />`. The publishable key is injected via a `data-` attribute from PHP
   env (we are not in SvelteKit, so there is no `$env/static/public`). When Clerk
   reports a signed-in user, the island redirects the browser to `/admin`.

2. **Admin chrome** — the admin layout (`app/views/admin/`) includes a small
   `<UserButton />` island (account management + sign-out). Mounting Clerk JS on
   every admin page also keeps the short-lived `__session` cookie fresh. Sign-out
   redirects to `/admin/login`.

3. **Backend gate** — `AuthMiddleware` is rewritten to call
   `AuthenticateRequest::authenticateRequest($request, $options)` where
   `$options = new AuthenticateRequestOptions(secretKey: CLERK_SECRET_KEY,
   authorizedParties: [APP_URL])`. Decision:
   - `isSignedIn()` is true **and** the token's `sub` (Clerk user id) equals
     `ADMIN_CLERK_USER_ID` → continue.
   - otherwise → `302` to `/admin/login`.
   - If the SDK returns a **handshake** state (the short-lived cookie has lapsed
     on a top-level navigation), honor the redirect location it provides so
     server-rendered navigation stays robust without depending on JS timing.

4. **Verification wrapper** — a thin `App\Support\ClerkAuth` support class wraps
   `AuthenticateRequest` and exposes the allow/deny decision (signed-in +
   allowlisted + handshake handling). This keeps the middleware thin and makes the
   decision logic unit-testable behind a seam (the actual networkless verifier is
   mocked in tests).

### Single-admin lockdown (critical)

A bare Clerk instance allows **anyone** to sign up, which would leave `/admin`
open to any Clerk account. Two independent layers:

1. **Clerk Dashboard** → Restrictions → sign-up mode **Restricted**, with the
   owner's email on the allowlist. Prevents account creation by others.
2. **PHP backstop** — `AuthMiddleware`/`ClerkAuth` requires the verified token's
   `sub` to equal `ADMIN_CLERK_USER_ID` (from `.env`). Even if (1) is
   misconfigured, an unknown Clerk user is rejected server-side.

## Configuration

`.env` (not committed) and `.env.example` gain:

- `CLERK_PUBLISHABLE_KEY` — frontend (publishable; safe to expose in the page).
- `CLERK_SECRET_KEY` — backend verification.
- `ADMIN_CLERK_USER_ID` — the one Clerk user id allowed into `/admin`.
- `APP_URL` — canonical site origin, used as `authorizedParties`.

`app/bootstrap.php` reads these (via `vlucas/phpdotenv`, already present) and makes
the publishable key available to the admin templates and the secret key/allowlist
available to the middleware. Local development uses a Clerk **development
instance** (separate keys from production).

## Build pipeline

- `package.json`: add `svelte-clerk` and its `@clerk/clerk-js` peer.
- `vite.config.*`: add a **second entry** (`islands/admin.js`) alongside the
  existing `islands/main.js`. The Clerk-bearing island lives in the admin bundle
  so Clerk's JS is **never shipped on public pages**. The public bundle
  (`Typewriter`, `Search`) is unchanged.
- The admin bundle is emitted through the existing Vite manifest helper
  (`AssetManifest` / `assets.js()`), referenced **only** from admin templates.
- `composer.json`: add `clerk/clerk-sdk-php` (its JWT dependency comes
  transitively).

## Retiring the PHP password auth

Identity is fully decoupled from content — the `users` table is referenced only by
auth (`UserRepository`, `bin/user.php`, `Auth.php`); posts/projects/pages have no
author FK. So the cutover is clean:

- Remove the password path: `Auth::attempt()` and the password branch of
  `AuthController` (the login route now just renders the Clerk island; logout is
  handled client-side by Clerk JS + a redirect).
- Remove `bin/user.php` (creating the admin user now happens in Clerk).
- **Leave the `users` table dormant** in the schema this round. Dropping it is a
  separate, destructive migration that can be done later if desired.
- Update `CLAUDE.md` (auth section, commands, "out of scope" rate-limit note) and
  any references to `bin/user.php` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

## Error handling

- Missing/invalid/expired `__session` cookie → treated as signed-out → `302` to
  `/admin/login` (same UX as today's redirect).
- Handshake state from the SDK → follow Clerk's redirect to refresh the cookie.
- Verification is **networkless** (JWKS cached after first fetch); a transient
  failure to fetch JWKS on cold start fails closed (redirect to login).

## Testing

- **Unit (PHPUnit, the only runner):** `ClerkAuth` decision logic with a mocked
  verifier — signed-in + allowlisted → allow; signed-in + wrong `sub` → deny;
  signed-out → deny; handshake → redirect. Update/remove the existing `Auth`
  password tests.
- **Not unit-tested:** real networkless JWT verification (needs a live Clerk
  token) — covered by manual E2E instead.
- **Manual E2E:** sign in / sign out against a Clerk **development instance**;
  confirm an unauthorized Clerk account is rejected by the PHP backstop.

## Out of scope

- Roles / multi-user / public accounts.
- Migrating existing `users` rows into Clerk (single admin — the owner creates one
  Clerk account directly).
- Dropping the `users` table (deferred, destructive).
- Production Clerk instance provisioning beyond documenting the required keys.

## Open implementation-time checks

These are verified against the actual SDK during implementation, not assumed:

1. `AuthenticateRequest::authenticateRequest()` accepts a **PSR-7
   `ServerRequestInterface`** (Slim's request) directly, or whether a light
   adapter is needed.
2. The exact shape of the handshake/redirect signal on `RequestState` and how to
   surface its redirect location.
3. The precise `svelte-clerk/client` API for passing a publishable key **outside**
   SvelteKit's `$env` (prop on `ClerkProvider`) and mounting it via the existing
   `data-island` registry.
