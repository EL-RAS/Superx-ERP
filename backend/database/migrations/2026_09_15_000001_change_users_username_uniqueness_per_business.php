<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usernames are now unique PER TENANT (business), not globally. Each tenant
 * has its own physical `users` table (tenant_{id}) whose `username` column is
 * already unique in that scope — this migration relaxes the CENTRAL mirror
 * table so two different businesses may adopt the same handle.
 *
 * Platform-owner rows carry `business_id = null`; a partial unique index keeps
 * their usernames globally unique (pgsql) since the composite index treats
 * NULLs as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->unique(['business_id', 'username'], 'users_business_id_username_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX users_username_unique_null_business ON users (username) WHERE business_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS users_username_unique_null_business');
            }
            $table->dropUnique('users_business_id_username_unique');
            $table->unique('username', 'users_username_unique');
        });
    }
};
