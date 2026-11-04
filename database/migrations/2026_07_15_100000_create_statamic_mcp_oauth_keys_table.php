<?php

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(KeyStore::TABLE, function (Blueprint $table) {
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
