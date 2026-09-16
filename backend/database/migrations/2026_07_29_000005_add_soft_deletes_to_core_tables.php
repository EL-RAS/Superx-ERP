<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['products', 'invoices', 'customers', 'suppliers', 'warehouses', 'categories',
                   'accounts', 'payments', 'purchase_orders', 'promotions', 'product_batches',
                   'product_variants', 'serial_numbers'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['products', 'invoices', 'customers', 'suppliers', 'warehouses', 'categories',
                   'accounts', 'payments', 'purchase_orders', 'promotions', 'product_batches',
                   'product_variants', 'serial_numbers'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
