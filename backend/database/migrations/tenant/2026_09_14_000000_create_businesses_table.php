<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's own shop profile — the multi-tenant home of the business
 * record. Seeded by TenantDatabaseSeeder from the provisioning payload
 * (which includes the business type's default settings), so a freshly
 * minted tenant database arrives pre-filled per business type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('business_type_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('activating');
            $table->jsonb('settings')->nullable();
            $table->string('plan')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->date('subscription_starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};