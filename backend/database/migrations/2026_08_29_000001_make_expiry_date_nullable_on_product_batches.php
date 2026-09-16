<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imported/quick-added batches have no known expiry when none is supplied,
     * and FEFO + inventory queries already treat a NULL expiry as "never
     * expires". Relax the column so initial batches can be seeded without one.
     */
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->date('expiry_date')->nullable(false)->change();
        });
    }
};
