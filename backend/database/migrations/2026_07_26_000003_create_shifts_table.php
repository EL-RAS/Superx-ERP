<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('shift_number');
            $table->decimal('opening_balance', 15, 4)->default(0);
            $table->decimal('closing_balance', 15, 4)->nullable();
            $table->decimal('expected_cash', 15, 4)->nullable();
            $table->decimal('actual_cash', 15, 4)->nullable();
            $table->decimal('variance', 15, 4)->nullable();
            $table->decimal('total_sales', 15, 4)->default(0);
            $table->decimal('total_refunds', 15, 4)->default(0);
            $table->decimal('total_discounts', 15, 4)->default(0);
            $table->integer('total_transactions')->default(0);
            $table->jsonb('payment_breakdown')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'shift_number']);

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
