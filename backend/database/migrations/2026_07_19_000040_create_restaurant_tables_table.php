<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->string('number');
            $table->string('section_id')->nullable();
            $table->integer('seats')->default(4);
            $table->string('shape')->default('square');
            $table->string('status')->default('available');
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->unsignedBigInteger('current_order_id')->nullable();
            $table->timestamp('occupied_since')->nullable();
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
        Schema::dropIfExists('restaurant_tables');
    }
};
