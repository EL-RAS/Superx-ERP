<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->string('ticket_number');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_name');
            $table->string('device_serial')->nullable();
            $table->text('issue_description');
            $table->string('status')->default('received');
            $table->decimal('estimated_cost', 15, 4)->default(0);
            $table->decimal('final_cost', 15, 4)->default(0);
            $table->string('technician')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
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
        Schema::dropIfExists('service_tickets');
    }
};
