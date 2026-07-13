<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth-mode wrapper: preflight the oauth prerequisites — each failure answers
 * 503 with its specific remedy ON THIS ROUTE ONLY (bootAddon never throws for
 * misconfiguration) — then hand authentication to the 'api' guard (Passport).
 *
 * Deliberately ONE class instead of the [preflight, 'auth:api'] pair: Laravel's
 * middleware priority sorter hoists AuthenticatesRequests implementors, which
 * would run auth:api BEFORE the preflight and 500 on a missing api guard. For
 * the same reason this class must NEVER implement
 * Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests — it would also be
 * hoisted above the configured pre-auth throttle. The resolved-pipeline test
 * pins the ordering.
 *
 * The config-read checks run before class_exists(Passport) so every failure
 * branch stays honestly reachable in a suite without Passport installed;
 * mcp:doctor checks the same prerequisites without short-circuiting.
 */
class AuthenticateOAuth
{
    /**
     * The scope laravel/mcp advertises in its OAuth discovery metadata
     * (scopes_supported) and requests during the connector flow — see
     * Laravel\Mcp\Server\Registrar::ensureMcpScope(). Passport authenticates any
     * valid token; gating on this scope keeps a token minted for the host app's
     * own SPA/mobile API — which never carries it — from doubling as an MCP
     * entry point. Kept in sync with the vendor string (no public constant).
     */
    protected const MCP_SCOPE = 'mcp:use';

    public function handle(Request $request, Closure $next): Response
    {
        if ($failure = $this->preflightFailure()) {
            return $failure;
        }

        return $this->authenticateViaApiGuard($request, $next);
    }

    protected function preflightFailure(): ?Response
    {
        $prereqs = app(OAuthPrerequisites::class);

        // The requirement is Eloquent users, so OAuthPrerequisites tests the
        // RESOLVED driver, not the repository name (mcp:doctor applies the
        // same predicate).
        if (! $prereqs->usersAreEloquent()) {
            return $this->unavailable(
                "OAuth mode requires database (Eloquent) users — a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users', or switch to token mode ('auth' => 'token')."
            );
        }

        // Driver, not just presence: a pre-existing session/token/sanctum
        // 'api' guard would let OAuth discovery and token issuance complete,
        // then 401-loop on tokens the guard ignores — misconfiguration
        // presenting as credential failure.
        if (! $prereqs->apiGuardIsPassport()) {
            return $this->unavailable(
                "OAuth mode requires an 'api' guard. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'."
            );
        }

        if (! $prereqs->passportInstalled()) {
            return $this->unavailable(
                "OAuth mode requires Laravel Passport. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token')."
            );
        }

        return null;
    }

    protected function authenticateViaApiGuard(Request $request, Closure $next): Response
    {
        // Downstream consumers (EnsureMcpPermission, Request::user(),
        // User::current()) must resolve from the guard Passport authenticated.
        Auth::shouldUse('api');

        $guard = Auth::guard('api');

        if (! $guard->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $this->tokenGrantsMcpScope($guard->user())) {
            return response()->json(
                ['error' => sprintf('The access token is missing the required "%s" scope.', self::MCP_SCOPE)],
                403,
                ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', self::MCP_SCOPE)],
            );
        }

        return $next($request);
    }

    /**
     * Passport's '*' superscope satisfies tokenCan by design (a deliberate
     * full-access grant), so this rejects only tokens issued for other purposes.
     * A user without HasApiTokens can't prove scope — fail closed; OAuth mode
     * requires Passport users, so this branch only fires on a misconfigured guard.
     */
    protected function tokenGrantsMcpScope(?Authenticatable $user): bool
    {
        return $user instanceof Authenticatable
            && method_exists($user, 'tokenCan')
            && $user->tokenCan(self::MCP_SCOPE);
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
