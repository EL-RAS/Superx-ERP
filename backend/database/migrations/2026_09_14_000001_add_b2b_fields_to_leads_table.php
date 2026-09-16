<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->string('subdomain')->nullable()->after('email');
            // The central Business mirror created when the owner provisions the tenant.
            $table->uuid('tenant_business_id')->nullable()->after('status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('tenant_business_id');
            $table->timestamp('provisioned_at')->nullable()->after('approved_by');

            $table->index('subdomain');
            $table->index('tenant_business_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_business_id']);
            $table->dropIndex(['subdomain']);
            $table->dropColumn(['provisioned_at', 'approved_by', 'tenant_business_id', 'subdomain', 'email']);
        });
    }
};