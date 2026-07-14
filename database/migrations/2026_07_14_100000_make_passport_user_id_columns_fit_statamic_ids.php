<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's stock tables give user_id a bigint column, but Statamic ids are
 * UUID strings for file users (and for Eloquent users imported from files).
 * Convert user_id to string(36) wherever a Passport table carries one —
 * integer ids from a stock Eloquent install still fit, so the change is safe
 * on every setup. Loaded only in OAuth mode; timestamped after Passport's
 * published migrations so a single `php artisan migrate` orders correctly.
 *
 * One-way on purpose: reverting to bigint would truncate UUID rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No ->index() here: Passport's original migrations already created
        // the user_id indexes, and they survive the column type change.
        $columns = [
            'oauth_auth_codes' => fn (Blueprint $table) => $table->string('user_id', 36)->change(),
            'oauth_access_tokens' => fn (Blueprint $table) => $table->string('user_id', 36)->nullable()->change(),
            'oauth_device_codes' => fn (Blueprint $table) => $table->string('user_id', 36)->nullable()->change(),
        ];

        foreach ($columns as $table => $change) {
            if ($this->needsConversion($table)) {
                Schema::table($table, $change);
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty — see the class comment.
    }

    protected function needsConversion(string $table): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'user_id')
            && str_contains(strtolower(Schema::getColumnType($table, 'user_id')), 'int');
    }
};
