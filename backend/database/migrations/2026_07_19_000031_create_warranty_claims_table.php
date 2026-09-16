<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('serial_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_number');
            $table->text('issue_description');
            $table->string('status')->default('open');
            $table->text('resolution')->nullable();
            $table->decimal('repair_cost', 15, 4)->default(0);
            $table->timestamp('reported_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('technician')->nullable();
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
        Schema::dropIfExists('warranty_claims');
    }
};
