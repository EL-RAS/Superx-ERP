<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Tenant baseline seeder — runs inside every freshly created tenant database
 * (via `tenants:seed` / the provisioning pipeline). Writes the shop profile
 * that the platform owner staged at provisioning time, including the business
 * type's default settings, so each new tenant arrives pre-configured.
 *
 * The provisioning service stores a compact `shop` payload on the tenant
 * as a top-level virtual attribute (VirtualColumn folds it into data.shop):
 *   shop = {
 *     id, name, slug, plan, city, contact_phone,
 *     business_type_id, status,
 *     settings: <business type default_settings already merged>
 *   }
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = tenancy()->tenant;

        if (! $tenant instanceof Tenant) {
            return;
        }

        // VirtualColumn exposes the payload as top-level attributes once decoded,
        // but the seeder can be invoked while the model is still encoded (data
        // folded into a single jsonb row). Read either shape.
        $shop = null;
        if (isset($tenant->shop) && is_array($tenant->shop)) {
            $shop = $tenant->shop;
        } elseif (is_array($tenant->getAttribute('data'))) {
            $shop = data_get($tenant->getAttribute('data'), 'shop');
        }

        if (! is_array($shop) || empty($shop['id'])) {
            return;
        }

        DB::table('businesses')->updateOrInsert(
            ['id' => $shop['id']],
            [
                'id' => $shop['id'],
                'business_type_id' => $shop['business_type_id'] ?? null,
                'name' => $shop['name'] ?? '',
                'slug' => $shop['slug'] ?? $shop['id'],
                'status' => $shop['status'] ?? 'activating',
                'settings' => json_encode($shop['settings'] ?? []),
                'plan' => $shop['plan'] ?? 'standard',
                'contact_phone' => $shop['contact_phone'] ?? null,
                'city' => $shop['city'] ?? null,
                'subscription_starts_at' => $shop['subscription_starts_at'] ?? null,
                'expires_at' => $shop['expires_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
