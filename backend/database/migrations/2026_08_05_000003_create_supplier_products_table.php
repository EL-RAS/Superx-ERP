<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id')->index();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->decimal('catalog_cost', 15, 4)->nullable();
            $table->boolean('is_imported')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();

            $table->unique(['business_id', 'supplier_id', 'name']);
        });

        // Backfill: seed the catalog from every historically ordered item so the
        // Create-PO drawer can offer each supplier's known products out of the box.
        DB::statement(<<<'SQL'
            INSERT INTO supplier_products
                (business_id, supplier_id, product_id, name, catalog_cost, is_imported, created_at, updated_at)
            SELECT
                po.business_id,
                po.supplier_id,
                MIN(poi.product_id) AS product_id,
                poi.name,
                MIN(poi.unit_cost) AS catalog_cost,
                true,
                NOW(),
                NOW()
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE po.supplier_id IS NOT NULL
              AND po.deleted_at IS NULL
              AND poi.product_id IS NOT NULL
            GROUP BY po.business_id, po.supplier_id, poi.name
            ON CONFLICT (business_id, supplier_id, name) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
