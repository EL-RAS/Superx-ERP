<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('size_top')->nullable();
            $table->string('size_bottom')->nullable();
            $table->string('shoe_size')->nullable();
            $table->string('fit_preference')->nullable();
            $table->jsonb('preferred_brands')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'size_top',
                'size_bottom',
                'shoe_size',
                'fit_preference',
                'preferred_brands',
            ]);
        });
    }
};
