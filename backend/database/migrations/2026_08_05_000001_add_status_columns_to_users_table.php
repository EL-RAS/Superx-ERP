<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('is_primary_admin')->default(false)->after('is_active');
        });

        // Mark the earliest-created admin of each business as the primary admin.
        DB::statement(<<<'SQL'
            UPDATE users
            SET is_primary_admin = true
            WHERE id IN (
                SELECT DISTINCT ON (business_id) id
                FROM users
                WHERE role = 'admin'
                ORDER BY business_id, id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_primary_admin', 'is_active']);
        });
    }
};
