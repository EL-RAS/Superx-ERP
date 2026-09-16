<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id');
            $table->string('kind'); // invoice | purchase_order | grn
            $table->string('prefix'); // e.g. INV- / PO- / GRN-
            $table->unsignedBigInteger('current')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'kind', 'prefix']);

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
