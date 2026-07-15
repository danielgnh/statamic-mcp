<?php

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's signing keys, managed the way its other OAuth state already is:
 * in the database, shared across every server of the environment, surviving
 * releases and read-only filesystems. One row, private key only (the public
 * key is derived), encrypted at rest with APP_KEY. Loaded only in OAuth mode,
 * like the addon's user_id conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(KeyStore::TABLE, function (Blueprint $table) {
            // Fixed single-row id: two servers provisioning simultaneously
            // collide on the primary key and converge on the winner's pair.
            $table->unsignedTinyInteger('id')->primary();
            $table->text('private_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(KeyStore::TABLE);
    }
};
