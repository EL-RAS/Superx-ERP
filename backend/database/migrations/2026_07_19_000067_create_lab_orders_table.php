<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('medical_record_id')->nullable()->constrained('medical_records');
            $table->string('order_number');
            $table->string('test_name');
            $table->string('test_type');
            $table->string('status')->default('ordered');
            $table->jsonb('results')->nullable();
            $table->text('results_notes')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_orders');
    }
};
