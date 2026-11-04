<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class ConnectionRepository
{
    public function ready(): bool
    {
        return app(OAuthPrerequisites::class)->passportInstalled()
            && Schema::hasTable('oauth_access_tokens');
    }

    /**
     * @return Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: Carbon, last_refreshed_at: Carbon, active: bool}>
     */
    public function all(): Collection
    {
        if (! $this->ready()) {
            return collect();
        }

        $tokenModel = Passport::tokenModel();

        $pairs = $tokenModel::query()
            ->whereNotNull('user_id')
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

        $foreignClientIds = $clients->reject(PassportBearerGuard::acceptsClient(...))->keys();
        $activePairs = $this->activePairKeys($tokenModel);

        return $pairs
            ->whereNotIn('client_id', $foreignClientIds)
            ->map(fn ($pair) => [
                'user_id' => (string) $pair->user_id,
                'client_id' => (string) $pair->client_id,
                'client_name' => (string) ($clients->get((string) $pair->client_id)->name ?? __('Unknown client')),
                'connected_at' => Carbon::parse($pair->connected_at),
                'last_refreshed_at' => Carbon::parse($pair->last_refreshed_at),
                'active' => $activePairs->has($pair->user_id.'|'.$pair->client_id),
            ])
            ->sortByDesc('last_refreshed_at')
            ->values();
    }

    /**
     * @param  class-string<Token>  $tokenModel
     * @return Collection<non-falsy-string, int> flipped for O(1) has() lookups
     */
    protected function activePairKeys(string $tokenModel): Collection
    {
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

    public function disconnect(string $userId, string $clientId): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $client = Passport::clientModel()::query()->find($clientId);

        if ($client && ! PassportBearerGuard::acceptsClient($client)) {
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
