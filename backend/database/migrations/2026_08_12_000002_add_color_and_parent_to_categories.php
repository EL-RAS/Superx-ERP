<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('color')->nullable()->after('name_ar');
            $table->foreignId('parent_id')->nullable()->after('color')->constrained('categories')->nullOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['color', 'parent_id']);
        });
    }
};
