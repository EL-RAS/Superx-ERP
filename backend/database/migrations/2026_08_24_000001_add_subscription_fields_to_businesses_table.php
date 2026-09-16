<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('plan')->default('standard')->after('status');
            $table->string('contact_phone')->nullable()->after('plan');
            $table->string('city')->nullable()->after('contact_phone');
            $table->date('subscription_starts_at')->nullable()->after('city');
            $table->date('expires_at')->nullable()->after('subscription_starts_at');
            $table->integer('max_pos_registers')->nullable()->after('expires_at');

            $table->index('expires_at');
        });

        // SuperX platform-owner accounts live outside any tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('business_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'plan',
                'contact_phone',
                'city',
                'subscription_starts_at',
                'expires_at',
                'max_pos_registers',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('business_id')->nullable(false)->change();
        });
    }
};
