<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth-mode wrapper: preflight the oauth prerequisites — each failure answers
 * 503 with its specific remedy ON THIS ROUTE ONLY (bootAddon never throws for
 * misconfiguration) — then authenticate via the addon's own guard, which
 * validates the bearer with Passport's ResourceServer and resolves the user
 * through the Statamic repository (file or Eloquent users alike).
 *
 * Deliberately ONE class instead of a [preflight, auth] middleware pair:
 * Laravel's middleware priority sorter hoists AuthenticatesRequests
 * implementors, which would run authentication BEFORE the preflight and 500 on
 * a missing Passport install. For the same reason this class must NEVER
 * implement Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests — it
 * would also be hoisted above the configured pre-auth throttle. The
 * resolved-pipeline test pins the ordering.
 */
class AuthenticateOAuth
{
    /**
     * The scope laravel/mcp advertises in its OAuth discovery metadata
     * (scopes_supported) and requests during the connector flow — see
     * Laravel\Mcp\Server\Registrar::ensureMcpScope(). Passport signs any scope
     * the flow granted into the token; gating on this one keeps a token minted
     * for the host app's own SPA/mobile API — which never carries it — from
     * doubling as an MCP entry point. Kept in sync with the vendor string (no
     * public constant).
     */
    protected const MCP_SCOPE = 'mcp:use';

    public function handle(Request $request, Closure $next): Response
    {
        if ($failure = $this->preflightFailure()) {
            return $failure;
        }

        if (! Auth::guard(PassportBearerGuard::GUARD)->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Pin the default guard only AFTER successful authentication (the
        // order Laravel's own Authenticate middleware uses): downstream
        // consumers (EnsureMcpPermission, Request::user(), User::current())
        // must resolve from the guard that authenticated, but a failing
        // bearer must never become the default — the exception handler's
        // context gathering calls Auth::id() and would re-drive the guard.
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

        // Without keys the ResourceServer constructor throws a raw 500 —
        // preflight it into an actionable 503 instead.
        if (! $prereqs->passportKeysExist()) {
            return $this->unavailable(
                "Passport's encryption keys are missing. Run 'php artisan passport:keys', or provide them via the PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY environment variables."
            );
        }

        return null;
    }

    /**
     * Scopes come off the validated token via the guard's request attribute —
     * not $user->tokenCan(), which only exists on Eloquent models carrying
     * HasApiTokens. Passport's '*' superscope passes by design (a deliberate
     * full-access grant); anything else must name mcp:use exactly.
     */
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
        ], 503, ['Retry-After' => '60']); // RFC 9110 pacing for well-behaved retry clients
    }
}
