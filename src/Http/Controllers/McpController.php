<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tools → MCP. Every route sits behind 'can:access mcp' (routes/cp.php).
 * Connections lists tokens and OAuth connections: a user's own, or
 * everyone's for a super admin.
 */
class McpController extends CpController
{
    /**
     * The nav item's target. The sidebar links through Inertia, and
     * Inertia::location() sends it straight to the Blade page instead of
     * rendering that page twice.
     */
    public function index(): Response
    {
        return Inertia::location(cp_route('mcp.connections.index'));
    }

    public function connections(): View
    {
        $user = User::current();

        abort_if($user === null, 401);

        $isSuper = $user->isSuper();
        $endpoint = url(config()->string('statamic.mcp.route'));
        $oauthMode = config('statamic.mcp.auth') === 'oauth';
        $connections = app(ConnectionRepository::class);

        return view('statamic-mcp::mcp.connections', [
            'tokens' => $this->presentTokens(
                app(TokenRepository::class)->all(),
                $isSuper ? null : (string) $user->id()
            ),
            'connections' => $oauthMode
                ? $this->presentConnections($connections->all(), $isSuper ? null : (string) $user->id())
                : collect(),
            'oauthReady' => $oauthMode && $connections->ready(),
            'isSuper' => $isSuper,
            'oauthMode' => $oauthMode,
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ]);
    }

    /**
     * tokens.yaml is hand-editable, so records may be partial — every key is
     * coalesced so a pruned key can't 500 the page.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return Collection<int, array{id: string, name: mixed, email: mixed, created_at: Carbon, expires_at: Carbon|null, expired: bool}>
     */
    protected function presentTokens(array $records, ?string $onlyUserId): Collection
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
    protected function presentConnections(Collection $connections, ?string $onlyUserId): Collection
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
