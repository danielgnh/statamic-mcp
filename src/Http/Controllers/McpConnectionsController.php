<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Danielgnh\StatamicMcp\Support\McpRoute;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class McpConnectionsController extends CpController
{
    public function index(TokenRepository $tokens, ConnectionRepository $connections): View
    {
        $user = User::current();

        abort_if($user === null, 401);

        $isSuper = $user->isSuper();
        $path = McpRoute::path();
        $endpoint = $path === null ? null : url($path);
        $oauthMode = config('statamic.mcp.auth') === 'oauth';

        return view('statamic-mcp::mcp.connections', [
            'tokens' => $this->presentTokens(
                $tokens->all(),
                $isSuper ? null : (string) $user->id()
            ),
            'connections' => $oauthMode
                ? $this->presentConnections($connections->all(), $isSuper ? null : (string) $user->id())
                : collect(),
            'oauthReady' => $oauthMode && $connections->ready(),
            'isSuper' => $isSuper,
            'oauthMode' => $oauthMode,
            'insecureUrl' => $endpoint !== null && ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ]);
    }

    public function destroy(ConnectionRepository $connections, string $clientId, string $userId): RedirectResponse
    {
        $user = User::current();

        abort_if($user === null, 401);

        abort_unless($user->isSuper() || $userId === (string) $user->id(), 403);

        abort_unless($connections->disconnect($userId, $clientId), 404);

        return redirect()->to(cp_route('mcp.connections.index'))->with('success', __('Connection disconnected.'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return Collection<int, array{id: string, name: mixed, user_id: mixed, user: UserContract|null, created_at: Carbon, expires_at: Carbon|null, expired: bool}>
     */
    protected function presentTokens(array $records, ?string $onlyUserId): Collection
    {
        /** @var Collection<int, array{id: string, name: mixed, user_id: mixed, user: UserContract|null, created_at: Carbon, expires_at: Carbon|null, expired: bool}> $presented */
        $presented = collect($records)
            ->filter(fn ($record) => $onlyUserId === null || ($record['user'] ?? null) === $onlyUserId)
            ->map(function ($record, $tokenId) {
                $userId = $record['user'] ?? '';
                $expiresAt = ($record['expires_at'] ?? null) ? Carbon::parse($record['expires_at']) : null;

                return [
                    'id' => $tokenId,
                    'name' => $record['name'] ?? null,
                    'user_id' => $userId,
                    'user' => User::find($userId),
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
     * @param  Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool}>  $connections
     * @return Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool, user: UserContract|null}>
     */
    protected function presentConnections(Collection $connections, ?string $onlyUserId): Collection
    {
        /** @var Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool, user: UserContract|null}> $presented */
        $presented = $connections
            ->filter(fn ($connection) => $onlyUserId === null || $connection['user_id'] === $onlyUserId)
            ->map(fn ($connection) => array_merge($connection, [
                'user' => User::find($connection['user_id']),
            ]))
            ->values();

        return $presented;
    }
}
