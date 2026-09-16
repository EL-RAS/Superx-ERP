<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('ticket_number');
            $table->string('item_description');
            $table->decimal('item_weight', 10, 4)->nullable();
            $table->integer('item_carat')->nullable();
            $table->text('issue_description');
            $table->decimal('estimated_cost', 15, 4)->default(0);
            $table->decimal('actual_cost', 15, 4)->default(0);
            $table->string('status')->default('received');
            $table->timestamp('received_at');
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('technician')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_tickets');
    }
};
