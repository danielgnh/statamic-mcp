<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/**
 * Derives connector "connections" — (user, client) pairs — live from
 * Passport's tables; the addon stores nothing. Every public method degrades
 * to an empty result when the OAuth prerequisites are missing, so the CP
 * utility page renders (with a remedy alert) on a half-configured site
 * instead of 500ing. Model classes come from Passport's accessors, so apps
 * that customize them stay supported.
 */
class ConnectionRepository
{
    /**
     * The same prerequisites AuthenticateOAuth preflights and mcp:doctor
     * checks, plus the migrated table — oauth mode switched on before
     * `php artisan migrate` must not break the page.
     */
    public function ready(): bool
    {
        $repository = config('statamic.users.repository', 'file');

        return config('statamic.users.repositories.'.$repository.'.driver') === 'eloquent'
            && config('auth.guards.api.driver') === 'passport'
            && class_exists(Passport::class)
            && Schema::hasTable('oauth_access_tokens');
    }

    /**
     * One row per (user, client) pair, newest activity first. 'active'
     * answers "can this connector still get in?" — a live refresh token
     * counts even when every access token has expired, because the refresh
     * grant needs no re-consent; but a revoked access token kills its
     * refresh token too (Passport checks both rows), so revoked never
     * counts.
     *
     * @return Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool}>
     */
    public function all(): Collection
    {
        if (! $this->ready()) {
            return collect();
        }

        $tokenModel = Passport::tokenModel();
        $tokens = $tokenModel::query()->get();

        if ($tokens->isEmpty()) {
            return collect();
        }

        $clientModel = Passport::clientModel();
        $clients = $clientModel::query()
            ->whereIn('id', $tokens->pluck('client_id')->unique())
            ->get()
            ->keyBy(fn ($client) => (string) $client->getKey());

        $refreshModel = Passport::refreshTokenModel();
        $refreshable = $refreshModel::query()
            ->whereIn('access_token_id', $tokens->pluck('id'))
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('access_token_id')
            ->flip();

        return $tokens
            ->groupBy(fn ($token) => $token->user_id.'|'.$token->client_id)
            ->map(function (Collection $group) use ($clients, $refreshable) {
                $first = $group->first();

                return [
                    'user_id' => (string) $first->user_id,
                    'client_id' => (string) $first->client_id,
                    'client_name' => $clients->get((string) $first->client_id)->name ?? __('Unknown client'),
                    'connected_at' => $group->min('created_at'),
                    'last_refreshed_at' => $group->max('created_at'),
                    'active' => $group->contains(fn ($token) => $this->usable($token, $refreshable)),
                ];
            })
            ->sortByDesc('last_refreshed_at')
            ->values();
    }

    /**
     * Revokes every access token for the pair AND each token's refresh
     * tokens — revoking only access tokens would leave a silent way back in
     * via the refresh grant. Returns false when the pair has no tokens at
     * all (the controller 404s); an already-dead pair is a successful no-op.
     */
    public function disconnect(string $userId, string $clientId): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $tokenModel = Passport::tokenModel();

        $ids = $tokenModel::query()
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return false;
        }

        $refreshModel = Passport::refreshTokenModel();
        $refreshModel::query()->whereIn('access_token_id', $ids)->update(['revoked' => true]);

        $tokenModel::query()->whereIn('id', $ids)->update(['revoked' => true]);

        return true;
    }

    protected function usable(object $token, Collection $refreshable): bool
    {
        if ($token->revoked) {
            return false;
        }

        $expired = $token->expires_at !== null && $token->expires_at->isPast();

        return ! $expired || $refreshable->has((string) $token->getKey());
    }
}
