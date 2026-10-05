<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');

            // users.id is a big-increment id (not a uuid), so the morph is
            // written out explicitly rather than via uuidMorphs().
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->index(['notifiable_type', 'notifiable_id']);

            // jsonb (not Laravel's stock `text`) so tag/severity lookups can be
            // queried with `data->tag`, matching every other JSON column here.
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
