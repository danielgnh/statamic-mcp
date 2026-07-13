<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

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
     * checks — delegated to OAuthPrerequisites so the three can never drift —
     * plus the migrated table: oauth mode switched on before `php artisan
     * migrate` must not break the page.
     */
    public function ready(): bool
    {
        $prereqs = app(OAuthPrerequisites::class);

        return $prereqs->usersAreEloquent()
            && $prereqs->apiGuardIsPassport()
            && $prereqs->passportInstalled()
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

        // One row per (user, client) pair, aggregated in the database — the
        // full historical token table never lands in memory, only the
        // connections it collapses to.
        $pairs = $tokenModel::query()
            ->select('user_id', 'client_id')
            ->selectRaw('MIN(created_at) as connected_at')
            ->selectRaw('MAX(created_at) as last_refreshed_at')
            ->groupBy('user_id', 'client_id')
            ->get();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $clientModel = Passport::clientModel();
        $clients = $clientModel::query()
            ->whereIn('id', $pairs->pluck('client_id')->unique())
            ->get()
            ->keyBy(fn ($client) => (string) $client->getKey());

        $activePairs = $this->activePairKeys($tokenModel);

        return $pairs
            ->map(fn ($pair) => [
                'user_id' => (string) $pair->user_id,
                'client_id' => (string) $pair->client_id,
                'client_name' => $clients->get((string) $pair->client_id)->name ?? __('Unknown client'),
                'connected_at' => Carbon::parse($pair->connected_at),
                'last_refreshed_at' => Carbon::parse($pair->last_refreshed_at),
                'active' => $activePairs->has($pair->user_id.'|'.$pair->client_id),
            ])
            ->sortByDesc('last_refreshed_at')
            ->values();
    }

    /**
     * The set of "{user_id}|{client_id}" pairs that still have a way in,
     * resolved in the database so the scan is bounded by live tokens, never
     * the whole history. A pair is active when it holds a token that is not
     * revoked AND (not expired, OR backed by a live refresh token) — the same
     * predicate the old in-PHP usable() applied, one Passport row at a time.
     *
     * @param  class-string<Token>  $tokenModel
     * @return Collection<string, int> flipped for O(1) has() lookups
     */
    protected function activePairKeys(string $tokenModel): Collection
    {
        // Non-revoked, unexpired refresh tokens keep an otherwise-expired
        // access token alive (the refresh grant needs no re-consent).
        $refreshModel = Passport::refreshTokenModel();
        $liveRefreshTokenIds = $refreshModel::query()
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('access_token_id');

        return $tokenModel::query()
            ->select('user_id', 'client_id')
            ->distinct()
            ->where('revoked', false)
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now())
                ->orWhereIn('id', $liveRefreshTokenIds))
            ->get()
            ->map(fn ($token) => $token->user_id.'|'.$token->client_id)
            ->flip();
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
}
