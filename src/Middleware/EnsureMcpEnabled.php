<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next, ?string $auth = null): Response
    {
        abort_unless(config('statamic.mcp.enabled') && in_array($auth, [null, self::currentAuth()], true), 404);

        return $next($request);
    }

    public static function forCurrentAuth(): string
    {
        return self::class.':'.self::currentAuth();
    }

    protected static function currentAuth(): string
    {
        return config('statamic.mcp.auth') === 'oauth' ? 'oauth' : 'token';
    }
}
