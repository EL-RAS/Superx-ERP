<?php

use App\Models\Business;
use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->jsonb('permissions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        // Seed system roles for businesses that already exist. New businesses
        // are seeded automatically by the Business::created hook.
        foreach (Business::pluck('id') as $businessId) {
            RbacService::seedForBusiness((string) $businessId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
