<?php

namespace Danielgnh\StatamicMcp\CP;

use Danielgnh\StatamicMcp\Http\Controllers\McpConnectionsController;
use Danielgnh\StatamicMcp\Http\Controllers\McpTokensController;
use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
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
                ->title(__('MCP Access'))
                ->icon('key')
                ->description(__('Manage MCP access tokens and OAuth connector connections.'))
                ->view('statamic-mcp::utilities.mcp-tokens', fn (Request $request) => static::viewData($request))
                ->routes(function ($router) {
                    $router->post('/', [McpTokensController::class, 'store'])->name('store');
                    $router->delete('connections/{clientId}/{userId}', [McpConnectionsController::class, 'destroy'])->name('connections.destroy');
                    $router->delete('{tokenId}', [McpTokensController::class, 'destroy'])->name('destroy');
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function viewData(Request $request): array
    {
        $user = User::current();

        abort_if($user === null, 401);

        $isSuper = $user->isSuper();
        $endpoint = url(config()->string('statamic.mcp.route'));
        $oauthMode = config('statamic.mcp.auth') === 'oauth';
        $connections = app(ConnectionRepository::class);

        return [
            'tokens' => static::presentTokens(
                app(TokenRepository::class)->all(),
                $isSuper ? null : (string) $user->id()
            ),
            'connections' => $oauthMode
                ? static::presentConnections($connections->all(), $isSuper ? null : (string) $user->id())
                : collect(),
            'oauthReady' => $oauthMode && $connections->ready(),
            'isSuper' => $isSuper,
            'lacksAccessMcp' => ! $isSuper && ! $user->hasPermission('access mcp'),
            'oauthMode' => $oauthMode,
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ];
    }

    /**
     * tokens.yaml is hand-editable, so records may be partial — every key is
     * coalesced so a pruned key can't 500 the page.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return Collection<int, array{id: string, name: mixed, email: mixed, created_at: Carbon, expires_at: Carbon|null, expired: bool}>
     */
    protected static function presentTokens(array $records, ?string $onlyUserId): Collection
    {
        /** @var Collection<int, array{id: string, name: mixed, email: mixed, created_at: Carbon, expires_at: Carbon|null, expired: bool}> $presented */
        $presented = collect($records)
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

        return $presented;
    }

    /**
     * Rows arrive shaped and sorted from the repository — this only filters
     * visibility and attaches the display email.
     *
     * @param  Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool}>  $connections
     * @return Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool, email: mixed}>
     */
    protected static function presentConnections(Collection $connections, ?string $onlyUserId): Collection
    {
        /** @var Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool, email: mixed}> $presented */
        $presented = $connections
            ->filter(fn ($connection) => $onlyUserId === null || $connection['user_id'] === $onlyUserId)
            ->map(fn ($connection) => array_merge($connection, [
                'email' => User::find($connection['user_id'])?->email() ?? $connection['user_id'],
            ]))
            ->values();

        return $presented;
    }
}
