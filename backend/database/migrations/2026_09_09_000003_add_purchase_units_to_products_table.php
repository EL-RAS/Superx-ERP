<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('purchase_unit')->nullable()->after('unit');
            $table->decimal('purchase_unit_qty', 10, 4)->default(1)->after('purchase_unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_unit', 'purchase_unit_qty']);
        });
    }
};