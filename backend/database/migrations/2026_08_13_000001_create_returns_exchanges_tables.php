<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_exchanges', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->string('return_number')->unique();
            $table->string('type'); // return | exchange
            $table->string('status')->default('completed');
            $table->string('refund_method')->nullable(); // cash | bank | credit (null for exchange)
            $table->decimal('returned_amount', 12, 2)->default(0);
            $table->decimal('exchanged_amount', 12, 2)->default(0);
            $table->decimal('difference_amount', 12, 2)->default(0); // exchanged - returned (+ customer pays, - refunded)
            $table->decimal('refund_amount', 12, 2)->default(0); // net cash back to the customer
            $table->foreignId('exchange_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();

            $table->index(['business_id', 'type', 'created_at']);
        });

        Schema::create('return_exchange_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_exchange_id')->constrained('return_exchanges')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('reason')->nullable();
            $table->boolean('is_exchange')->default(false);
            $table->timestamps();

            $table->index('return_exchange_id');
            $table->index(['invoice_item_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_exchange_items');
        Schema::dropIfExists('return_exchanges');
    }
};
