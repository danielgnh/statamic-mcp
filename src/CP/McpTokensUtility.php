<?php

namespace Danielgnh\StatamicMcp\CP;

use Danielgnh\StatamicMcp\Http\Controllers\McpConnectionsController;
use Danielgnh\StatamicMcp\Http\Controllers\McpTokensController;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
                    $router->delete('connections/{clientId}/{userId}', [McpConnectionsController::class, 'destroy'])->name('connections.destroy');
                    $router->delete('{tokenId}', [McpTokensController::class, 'destroy'])->name('destroy');
                });
        });
    }

    public static function viewData(Request $request): array
    {
        $user = User::current();
        $isSuper = $user->isSuper();
        $endpoint = url(config('statamic.mcp.route'));

        return [
            'tokens' => static::presentTokens(
                app(TokenRepository::class)->all(),
                $isSuper ? null : (string) $user->id()
            ),
            'isSuper' => $isSuper,
            'lacksAccessMcp' => ! $isSuper && ! $user->hasPermission('access mcp'),
            'oauthMode' => config('statamic.mcp.auth') === 'oauth',
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ];
    }

    /**
     * tokens.yaml is hand-editable, so records may be partial — every key is
     * coalesced so a pruned key can't 500 the page. The plain array type is
     * deliberate, mirroring Doctor::classifyTokens (repository return types
     * say complete records; widening them is a v1.1 candidate).
     */
    protected static function presentTokens(array $records, ?string $onlyUserId): Collection
    {
        return collect($records)
            ->filter(fn ($record) => $onlyUserId === null || ($record['user'] ?? null) === $onlyUserId)
            ->map(function ($record, $tokenId) {
                $userId = $record['user'] ?? '';
                $expiresAt = ($record['expires_at'] ?? null) ? Carbon::parse($record['expires_at']) : null;

                return [
                    'id' => $tokenId,
                    'name' => $record['name'] ?? null,
                    'email' => User::find($userId)?->email() ?? $userId,
                    'created_at' => Carbon::parse($record['created_at'] ?? Carbon::now()->toIso8601String()),
                    'expires_at' => $expiresAt,
                    'expired' => $expiresAt?->isPast() ?? false,
                ];
            })
            ->sortByDesc('created_at')
            ->values();
    }
}
