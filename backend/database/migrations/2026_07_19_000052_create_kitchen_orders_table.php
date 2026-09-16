<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->string('order_number');
            $table->string('table_number')->nullable();
            $table->string('order_type');
            $table->string('status')->default('new');
            $table->integer('priority')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('items');
            $table->string('station')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_orders');
    }
};
