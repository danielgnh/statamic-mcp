<?php

namespace Danielgnh\StatamicMcp\CP;

use Danielgnh\StatamicMcp\Http\Controllers\McpTokensController;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Statamic\Facades\Utility;

/**
 * Registers the "MCP Tokens" CP utility. Statamic supplies the permission
 * ('access mcp_tokens utility', auto-registered) and applies it as `can:`
 * middleware to the GET action and every custom route — no bespoke gate here.
 */
class McpTokensUtility
{
    public static function register(): void
    {
        Utility::extend(function () {
            Utility::register('mcp_tokens')
                ->title(__('MCP Tokens'))
                ->icon('key')
                ->description(__('Issue and revoke your own MCP access tokens.'))
                ->view('statamic-mcp::utilities.mcp-tokens', fn (Request $request) => static::viewData($request))
                ->routes(function ($router) {
                    $router->post('/', [McpTokensController::class, 'store'])->name('store');
                    $router->delete('{tokenId}', [McpTokensController::class, 'destroy'])->name('destroy');
                });
        });
    }

    public static function viewData(Request $request): array
    {
        $user = User::current();
        $isSuper = $user->isSuper();
        $endpoint = url(config('statamic.mcp.route'));

        $tokens = collect(app(TokenRepository::class)->all())
            ->when(! $isSuper, fn ($tokens) => $tokens->filter(
                fn ($record) => $record['user'] === (string) $user->id()
            ))
            ->map(function ($record, $tokenId) {
                $expiresAt = $record['expires_at'] ? Carbon::parse($record['expires_at']) : null;

                return [
                    'id' => $tokenId,
                    'name' => $record['name'],
                    'email' => User::find($record['user'])?->email() ?? $record['user'],
                    'created_at' => Carbon::parse($record['created_at']),
                    'expires_at' => $expiresAt,
                    'expired' => $expiresAt?->isPast() ?? false,
                ];
            })
            ->sortByDesc('created_at')
            ->values();

        return [
            'tokens' => $tokens,
            'isSuper' => $isSuper,
            'lacksAccessMcp' => ! $isSuper && ! $user->hasPermission('access mcp'),
            'oauthMode' => config('statamic.mcp.auth') === 'oauth',
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ];
    }
}
