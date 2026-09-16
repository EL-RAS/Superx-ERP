<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('police_book_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->string('entry_number');
            $table->string('entry_type');
            $table->string('item_description');
            $table->decimal('item_weight', 10, 4);
            $table->integer('item_carat');
            $table->string('metal_type')->default('gold');
            $table->string('customer_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('customer_id_number')->nullable();
            $table->date('transaction_date');
            $table->decimal('transaction_amount', 15, 4);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('police_book_entries');
    }
};
