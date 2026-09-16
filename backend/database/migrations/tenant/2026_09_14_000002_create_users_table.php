<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-side users table. Mirrors the landlord `users` shape so the same
 * `App\Models\User` model can authenticate against a tenant's own database
 * when a request is served through the tenant's subdomain (the account the
 * `/activate` wizard provisions lives here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('name');
            $table->string('email');
            $table->string('username')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary_admin')->default(false);
            $table->text('avatar')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['business_id', 'email']);

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};