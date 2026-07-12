<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Schema + seed helpers for Passport-leg tests. The tables mirror Passport's
 * schema for every column its models or ConnectionRepository touch; user_id
 * is a string (Passport uses foreignId) so file-user fixture ids work under
 * sqlite — production OAuth requires Eloquent users, but these tests exercise
 * grouping and revocation, not authentication.
 */
class OAuthFixtures
{
    public static function migratePassport(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris')->nullable();
            $table->text('grant_types')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('access_token_id')->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    /** Config that makes ConnectionRepository::ready() true (with the tables migrated). */
    public static function oauthReadyConfig(): void
    {
        config([
            'statamic.mcp.auth' => 'oauth',
            'statamic.users.repository' => 'eloquent',
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
        ]);
    }

    public static function client(string $name = 'Claude'): string
    {
        $model = Passport::clientModel();

        $attributes = [
            'id' => (string) Str::uuid(),
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }

    public static function accessToken(string $userId, string $clientId, array $overrides = []): string
    {
        $model = Passport::tokenModel();

        $attributes = array_merge([
            'id' => Str::random(40),
            'user_id' => $userId,
            'client_id' => $clientId,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ], $overrides);

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }

    public static function refreshToken(string $accessTokenId, array $overrides = []): string
    {
        $model = Passport::refreshTokenModel();

        $attributes = array_merge([
            'id' => Str::random(40),
            'access_token_id' => $accessTokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ], $overrides);

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }
}
