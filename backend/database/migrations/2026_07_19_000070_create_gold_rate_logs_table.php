<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->integer('carat');
            $table->decimal('rate_per_gram', 15, 4);
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_rate_logs');
    }
};
