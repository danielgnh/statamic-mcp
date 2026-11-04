<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOAuth
{
    protected const MCP_SCOPE = 'mcp:use';

    public function handle(Request $request, Closure $next): Response
    {
        if ($failure = $this->preflightFailure()) {
            return $failure;
        }

        if (! Auth::guard(PassportBearerGuard::GUARD)->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        Auth::shouldUse(PassportBearerGuard::GUARD);

        if (! $this->tokenGrantsMcpScope($request)) {
            return response()->json(
                ['error' => sprintf('The access token is missing the required "%s" scope.', self::MCP_SCOPE)],
                403,
                ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', self::MCP_SCOPE)],
            );
        }

        return $next($request);
    }

    protected function preflightFailure(): ?Response
    {
        $prereqs = app(OAuthPrerequisites::class);

        if (! $prereqs->passportInstalled()) {
            return $this->unavailable(
                "OAuth mode requires Laravel Passport. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token')."
            );
        }

        if (! $prereqs->passportKeysExist()) {
            if ($prereqs->passportKeysUndecryptable()) {
                return $this->unavailable(
                    "Passport's stored signing key can't be decrypted — APP_KEY changed since it was stored. Restore the previous APP_KEY, or delete the stored key row to let a fresh pair provision (every connected client must then reconnect)."
                );
            }

            return $this->unavailable(
                "Passport's encryption keys are missing. Run 'php artisan migrate' — the addon provisions a pair into the database automatically — or 'php please mcp:keys' to generate one now."
            );
        }

        return null;
    }

    protected function tokenGrantsMcpScope(Request $request): bool
    {
        $scopes = (array) $request->attributes->get(PassportBearerGuard::SCOPES_ATTRIBUTE, []);

        return array_intersect([self::MCP_SCOPE, '*'], $scopes) !== [];
    }

    protected function unavailable(string $remedy): Response
    {
        return response()->json([
            'error' => 'MCP OAuth mode is misconfigured.',
            'remedy' => $remedy,
            'doctor' => "Run 'php please mcp:doctor' to check every OAuth prerequisite at once.",
        ], 503, ['Retry-After' => '60']);
    }
}
