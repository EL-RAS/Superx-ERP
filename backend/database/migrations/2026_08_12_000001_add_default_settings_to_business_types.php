<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_types', function (Blueprint $table) {
            $table->jsonb('default_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_types', function (Blueprint $table) {
            $table->dropColumn('default_settings');
        });
    }
};
