<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ? User::fromUser($request->user()) : null;

        if (! $user || (! $user->isSuper() && ! $user->hasPermission('access mcp'))) {
            return response()->json([
                'error' => sprintf(
                    "requires 'access mcp' — grant it to a role of %s in the Control Panel",
                    $user?->email() ?? 'the connected user',
                ),
            ], 403);
        }

        return $next($request);
    }
}
