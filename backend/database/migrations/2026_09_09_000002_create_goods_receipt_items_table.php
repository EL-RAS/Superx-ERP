<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 2); // quantity in base/selling units
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('total', 15, 4);
            $table->decimal('purchase_quantity', 10, 4)->nullable(); // quantity as entered (purchase units)
            $table->decimal('purchase_unit_qty', 10, 4)->default(1); // base units per purchase unit
            $table->date('expiry_date')->nullable();
            $table->string('storage_location')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};