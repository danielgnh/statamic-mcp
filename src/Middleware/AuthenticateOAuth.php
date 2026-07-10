<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
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
 * mcp:doctor (T24) checks the same prerequisites without short-circuiting.
 */
class AuthenticateOAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($failure = $this->preflightFailure()) {
            return $failure;
        }

        return $this->authenticateViaApiGuard($request, $next);
    }

    protected function preflightFailure(): ?Response
    {
        // The repository NAME is arbitrary — a file-driven repository named
        // 'custom' would pass a name check, then fail confusingly at runtime.
        // The requirement is Eloquent users, so test the RESOLVED driver
        // (mcp:doctor applies the same predicate).
        $repository = config('statamic.users.repository', 'file');

        if (config('statamic.users.repositories.'.$repository.'.driver') !== 'eloquent') {
            return $this->unavailable(
                "OAuth mode requires database (Eloquent) users — a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users', or switch to token mode ('auth' => 'token')."
            );
        }

        // Driver, not just presence: a pre-existing session/token/sanctum
        // 'api' guard would let OAuth discovery and token issuance complete,
        // then 401-loop on tokens the guard ignores — misconfiguration
        // presenting as credential failure.
        if (config('auth.guards.api.driver') !== 'passport') {
            return $this->unavailable(
                "OAuth mode requires an 'api' guard. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'."
            );
        }

        if (! class_exists(Passport::class)) {
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

        if (! Auth::guard('api')->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
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
