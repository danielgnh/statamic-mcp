<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Schema + seed helpers for the OAuth tests. The tables mirror Passport's
 * schema for every column its models or ConnectionRepository touch, with the
 * string user_id columns the addon's own migration produces — Statamic ids
 * are UUID strings, whoever stores the users.
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
            $table->string('user_id', 36)->nullable()->index();
            $table->string('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->string('user_id', 36)->index();
            $table->string('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('access_token_id')->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    /**
     * Passport's STOCK shape — bigint user_id — for tests proving the doctor
     * flags it and the addon's migration converts it.
     */
    public static function migratePassportWithBigintUserIds(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->string('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    /** Config that makes OAuth mode look fully configured (with the tables migrated). */
    public static function oauthReadyConfig(): void
    {
        config([
            'statamic.mcp.auth' => 'oauth',
            'passport.private_key' => '-----BEGIN RSA PRIVATE KEY-----fixture',
            'passport.public_key' => '-----BEGIN PUBLIC KEY-----fixture',
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
