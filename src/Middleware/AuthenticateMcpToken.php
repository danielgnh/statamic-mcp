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

        // 3. Constant-time compare against the stored SHA-256. When no usable
        // hash is present (record missing, or a hand-edited record that lost
        // its hash key) an UNPREDICTABLE per-request dummy stands in so one
        // hash_equals still runs on every path (timing uniformity) — never a
        // fixed public string, which would BE the password for a hash-less
        // record. The $hasHash flag, not the compare, decides those cases:
        // a hash-less record ALWAYS 401s regardless of the secret sent.
        $record = $this->tokens->find($tokenId);

        $hasHash = is_string($record['hash'] ?? null);

        $knownHash = $hasHash
            ? $record['hash']
            : hash('sha256', bin2hex(random_bytes(32)));

        if (! hash_equals($knownHash, hash('sha256', $secret)) || ! $hasHash) {
            return $this->unauthenticated();
        }

        // 4. Expiry (TokenRepository::find intentionally returns expired
        // records; hand-edited records may lack the key entirely).
        $expiresAt = $record['expires_at'] ?? null;

        if ($expiresAt && Carbon::parse($expiresAt)->isPast()) {
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
