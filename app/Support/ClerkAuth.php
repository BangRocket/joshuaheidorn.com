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
     * Any verification error denies access (fail closed).
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

            return self::decide($state->isAuthenticated(), $subject, $this->adminUserId);
        } catch (\Throwable) {
            return false;
        }
    }
}
