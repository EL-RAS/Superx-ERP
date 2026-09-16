<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time expiring activation tokens issued when the platform owner
 * provisions a tenant ("Approve & Provision Tenant"). The token escorts
 * the first-time setup wizard on the tenant subdomain and is consumed
 * the moment the root admin account is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('tenant_id')->index();
            $table->uuid('business_id');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_tokens');
    }
};