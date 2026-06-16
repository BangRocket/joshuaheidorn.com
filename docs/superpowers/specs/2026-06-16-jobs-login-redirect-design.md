# Login Return-URL Redirect (Design Spec)

**Date:** 2026-06-16 · **Status:** Approved for implementation

## Problem

Visiting a Clerk-guarded route (`/jobs`, or any `/admin/...`) while signed out 302-redirects to
`/admin/login`, whose Clerk `<SignIn>` island has a hardcoded `forceRedirectUrl="/admin"`. So
after signing in you always land on the admin dashboard — never back on `/jobs` — and the login
page gives no hint of where you were headed.

## Goal

Carry the originally-requested path through the login flow and return there after sign-in, with a
contextual heading. One shared login page (no separate `/jobs/login`). Benefits `/admin` deep
links too, since `AuthMiddleware` guards both groups.

## Changes

1. **`App\Middleware\AuthMiddleware`** — on an unauthenticated request, redirect to
   `/admin/login?next=<rawurlencode(path[?query])>`. The existing `/admin/login` special-case
   stays (no redirect loop; no `next` added there).
2. **`App\Support\Auth::safeNext(?string $next): string`** — open-redirect guard. Returns `$next`
   only when it is a same-site relative path whose first segment is `/jobs` or `/admin`
   (regex `^/(jobs|admin)($|[/?])`); otherwise `/admin`. This blocks `//evil.com`,
   `https://evil`, `/etc/passwd`, `/jobsX`, empty/null.
3. **`App\Controllers\Admin\AuthController::loginForm`** — read `next` from the query, run it
   through `Auth::safeNext`, pass `redirect_url` and `login_context` (`"Jobs"` when the target
   starts with `/jobs`, else `"Admin"`) to the template.
4. **`app/views/admin/login.twig`** — heading shows `login_context`; inject `redirect_url` into the
   `AdminLogin` island's `data-props` alongside `publishableKey`.
5. **`islands/AdminLogin.svelte`** — accept a `redirectUrl` prop (default `/admin`); use it as
   `<SignIn forceRedirectUrl={redirectUrl}>`. Rebuild with `yarn build`.

## Security

`next` is `rawurlencode`d into the redirect (no header injection) and re-validated by `safeNext`
on read. Only `/jobs` and `/admin` prefixes are accepted (the only guarded routes), so an
attacker-supplied `next` can never produce an off-site or unexpected redirect.

## Testing

- **`AuthTest` (unit):** `safeNext` accepts `/jobs`, `/jobs/api/jobs`, `/admin`, `/admin/posts/5/edit`;
  rejects `//evil.com`, `https://evil`, `/etc/passwd`, `/jobsX`, `''`, `null` → `/admin`.
- **`JobsAuthTest` / `AdminAuthTest`:** signed-out guarded requests 302 to `/admin/login?next=…`
  carrying the right path; `/admin/login?next=%2Fjobs` renders 200 with the `redirect_url` in the
  page and a "Jobs" heading.
- Client prop wiring verified via the rendered `data-props` (server side) + a successful `yarn build`
  (svelte-clerk's `<SignIn>` can't be meaningfully unit-tested under jsdom).

## Out of scope

A separate `/jobs/login` route; changing Clerk config; sign-out redirect behavior.
