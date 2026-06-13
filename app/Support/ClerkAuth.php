<?php

declare(strict_types=1);

namespace App\Support;

use Clerk\Backend\Helpers\Jwks\AuthenticateRequest;
use Clerk\Backend\Helpers\Jwks\AuthenticateRequestOptions;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Verifies Clerk session cookies and authorizes the single admin user.
 *
 * Identity is owned by Clerk. PHP sessions are no longer used for auth
 * (only for CSRF tokens — see App\Support\Auth::start()).
 */
final class ClerkAuth
{
    /** @param string[] $authorizedParties */
    public function __construct(
        private string $secretKey,
        private array $authorizedParties,
        private string $adminUserId,
    ) {
    }

    /**
     * Parse a comma-separated list of authorized origins into a clean array.
     *
     * These are the app's OWN origins (e.g. `http://127.0.0.1:8088`), which is
     * what appears as the Clerk session token's `azp` claim — NOT the Clerk
     * Frontend API URL (`https://<slug>.clerk.accounts.dev`). Allowing several
     * lets dev accept both `127.0.0.1` and `localhost`, and prod list its
     * canonical origin(s).
     *
     * @return string[]
     */
    public static function parseAuthorizedParties(string $appUrl): array
    {
        $parts = array_map('trim', explode(',', $appUrl));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Pure authorization decision: is this verified session our single admin?
     * Fails closed when the allowlist is unconfigured (empty admin id).
     */
    public static function decide(bool $authenticated, ?string $subject, string $adminUserId): bool
    {
        return $authenticated
            && $adminUserId !== ''
            && $subject !== null
            && hash_equals($adminUserId, $subject);
    }

    /**
     * Verify the request's Clerk `__session` cookie (networkless) and authorize it.
     *
     * Any verification error denies access (fail closed). The reason for a denial
     * is logged (no secrets) so a misconfiguration is diagnosable instead of a
     * silent `/admin` ↔ `/admin/login` redirect loop.
     */
    public function isAdmin(Request $request): bool
    {
        try {
            $options = new AuthenticateRequestOptions(
                secretKey: $this->secretKey,
                authorizedParties: $this->authorizedParties,
            );
            $state = AuthenticateRequest::authenticateRequest($request, $options);
            $subject = $state->getPayload()?->sub ?? null;

            if (self::decide($state->isAuthenticated(), $subject, $this->adminUserId)) {
                return true;
            }

            error_log('[ClerkAuth] denied /admin — ' . ($state->isAuthenticated()
                ? 'authenticated user is not the configured admin'
                : 'session not verified: ' . ($state->getErrorReason()?->getId() ?? 'unknown')));

            return false;
        } catch (\Throwable $e) {
            error_log('[ClerkAuth] denied /admin — verification error: ' . $e->getMessage());

            return false;
        }
    }
}
