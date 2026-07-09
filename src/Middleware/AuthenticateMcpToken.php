<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must NEVER implement Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests:
 * Laravel's middleware priority sorter would hoist this class above the configured
 * pre-auth throttle on the MCP route. The ServiceProvider's resolved-pipeline test
 * pins the throttle-before-auth ordering.
 */
class AuthenticateMcpToken
{
    public function __construct(protected TokenRepository $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Length cap before any parsing or hashing (hash-DoS guard).
        $header = (string) $request->header('Authorization', '');

        if ($header === '' || strlen($header) > 256) {
            return $this->unauthenticated();
        }

        // 2. Positional parse: mcp_{tokenId}_{secret}.
        $parts = explode('_', (string) $request->bearerToken(), 3);

        if (count($parts) !== 3 || $parts[0] !== 'mcp' || $parts[1] === '' || $parts[2] === '') {
            return $this->unauthenticated();
        }

        [, $tokenId, $secret] = $parts;

        // 3. Constant-time compare against the stored SHA-256.
        $record = $this->tokens->find($tokenId);

        if (! $record || ! hash_equals($record['hash'], hash('sha256', $secret))) {
            return $this->unauthenticated();
        }

        // 4. Expiry (TokenRepository::find intentionally returns expired records).
        if ($record['expires_at'] && Carbon::parse($record['expires_at'])->isPast()) {
            return $this->unauthenticated();
        }

        // 5. Tokens die with their user — no orphan bookkeeping.
        if (! $user = User::find($record['user'])) {
            return $this->unauthenticated();
        }

        // 6. Authenticate on the auth manager so User::current() resolves.
        $guard = config('statamic.users.guards.cp', 'web');
        Auth::shouldUse($guard);
        Auth::setUser($user);

        return $next($request);
    }

    protected function unauthenticated(): Response
    {
        return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
