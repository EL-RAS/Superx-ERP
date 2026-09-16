<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('user_id')->index();
            $table->foreignId('product_id')->nullable()->index()->constrained('products')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->index()->constrained('product_batches')->nullOnDelete();
            $table->string('adjustment_number');
            $table->string('type');
            $table->decimal('quantity_before', 15, 4)->default(0);
            $table->decimal('quantity_adjusted', 15, 4);
            $table->decimal('quantity_after', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('completed');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'adjustment_number']);

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
        Schema::dropIfExists('inventory_adjustments');
    }
};
