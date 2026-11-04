<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        //
    }

    protected function needsConversion(string $table): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'user_id')
            && str_contains(strtolower(Schema::getColumnType($table, 'user_id')), 'int');
    }
};
