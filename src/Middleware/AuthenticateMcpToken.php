<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(protected TokenRepository $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if ($header === '' || strlen($header) > 256) {
            return $this->unauthenticated();
        }

        $parts = explode('_', (string) $request->bearerToken(), 3);

        if (count($parts) !== 3 || $parts[0] !== 'mcp' || $parts[1] === '' || $parts[2] === '') {
            return $this->unauthenticated();
        }

        [, $tokenId, $secret] = $parts;

        $record = $this->tokens->find($tokenId);

        $hasHash = is_string($record['hash'] ?? null);

        $knownHash = $hasHash
            ? $record['hash']
            : hash('sha256', bin2hex(random_bytes(32)));

        if (! hash_equals($knownHash, hash('sha256', $secret)) || ! $hasHash) {
            return $this->unauthenticated();
        }

        $expiresAt = $record['expires_at'] ?? null;

        if ($expiresAt && Carbon::parse($expiresAt)->isPast()) {
            return $this->unauthenticated();
        }

        if (! $user = User::find($record['user'] ?? null)) {
            return $this->unauthenticated();
        }

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
