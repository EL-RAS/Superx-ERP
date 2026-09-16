<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('phone');
            $table->text('message');
            $table->string('status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->index(['campaign_id', 'status']);
            $table->index(['business_id', 'campaign_id']);
            $table->unique(['campaign_id', 'customer_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('delivered_count')->default(0)->after('sent_count');
            $table->unsignedInteger('failed_count')->default(0)->after('delivered_count');
            $table->timestamp('started_at')->nullable()->after('sent_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['delivered_count', 'failed_count', 'started_at', 'completed_at']);
        });

        Schema::dropIfExists('campaign_messages');
    }
};
