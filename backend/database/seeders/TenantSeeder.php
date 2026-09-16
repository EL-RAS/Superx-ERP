<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Lead;
use App\Services\OnboardingService;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Provisions the demo stores through the full B2B onboarding pipeline — the
 * same contract the Owner Dashboard "Approve" button exercises:
 *   Lead → OnboardingService::provision() (central Business mirror + stancl
 *          Tenant + real tenant database + single-use activation token)
 *        → OnboardingService::activate() (root admin in the tenant DB + the
 *          central mirror user + shop status active + token consumed).
 *
 * Runs synchronously (jobs forced onto the `sync` queue) so the tenant database
 * is created/migrated/seeded before activate() reads it, and is idempotent per
 * subdomain — an already-provisioned domain is skipped so `db:seed` never
 * duplicates stores.
 */
class TenantSeeder extends Seeder
{
    /** @var list<array{business_name: string, type_slug: string, subdomain: string, owner: string, contact_email: string, phone: string, city: string, username: string, admin_name: string}> */
    private array $stores = [
        [
            'business_name' => 'Super Retail',
            'type_slug' => 'supermarket_hypermarket',
            'subdomain' => 'super-retail',
            'owner' => 'Retail Owner',
            'contact_email' => 'admin@superretail.com',
            'phone' => '+962785555001',
            'city' => 'Amman',
            'username' => 'retail_admin',
            'admin_name' => 'Retail Admin',
        ],
        [
            'business_name' => 'Fashion Hub',
            'type_slug' => 'clothing_apparel',
            'subdomain' => 'fashion-hub',
            'owner' => 'Fashion Owner',
            'contact_email' => 'admin@fashionhub.com',
            'phone' => '+962785555002',
            'city' => 'Amman',
            'username' => 'fashion_admin',
            'admin_name' => 'Fashion Admin',
        ],
        [
            'business_name' => 'TechZone',
            'type_slug' => 'electronics_warranty',
            'subdomain' => 'techzone',
            'owner' => 'TechZone Owner',
            'contact_email' => 'admin@techzone.com',
            'phone' => '+962785555003',
            'city' => 'Amman',
            'username' => 'tech_admin',
            'admin_name' => 'Tech Admin',
        ],
        [
            'business_name' => 'Byte Burger',
            'type_slug' => 'fast_food_kds',
            'subdomain' => 'byte-burger',
            'owner' => 'Byte Burger Owner',
            'contact_email' => 'admin@byteburger.com',
            'phone' => '+962785555004',
            'city' => 'Amman',
            'username' => 'burger_admin',
            'admin_name' => 'Byte Burger Admin',
        ],
        [
            'business_name' => 'Le Ciel',
            'type_slug' => 'fine_dining_reservations',
            'subdomain' => 'le-ciel',
            'owner' => 'Le Ciel Owner',
            'contact_email' => 'admin@leciel.com',
            'phone' => '+962785555005',
            'city' => 'Amman',
            'username' => 'leciel_admin',
            'admin_name' => 'Le Ciel Admin',
        ],
        [
            'business_name' => 'Smile Dental',
            'type_slug' => 'dental_charting',
            'subdomain' => 'smile-dental',
            'owner' => 'Dr. Aisha Noor',
            'contact_email' => 'admin@smiledental.com',
            'phone' => '+962785555006',
            'city' => 'Amman',
            'username' => 'dental_admin',
            'admin_name' => 'Dr. Aisha Noor',
        ],
        [
            'business_name' => 'Al-Shifa Clinic',
            'type_slug' => 'general_clinic',
            'subdomain' => 'al-shifa-clinic',
            'owner' => 'Dr. Hassan Youssef',
            'contact_email' => 'admin@alshifa.com',
            'phone' => '+962785555007',
            'city' => 'Amman',
            'username' => 'clinic_admin',
            'admin_name' => 'Dr. Hassan Youssef',
        ],
        [
            'business_name' => 'Al-Karim Jewelry',
            'type_slug' => 'jewelry_store',
            'subdomain' => 'al-karim-jewelry',
            'owner' => 'Karim Owner',
            'contact_email' => 'admin@alkarim.com',
            'phone' => '+962785555008',
            'city' => 'Amman',
            'username' => 'karim_admin',
            'admin_name' => 'Karim Admin',
        ],
    ];

    private const ADMIN_PASSWORD = 'password';

    public function run(): void
    {
        // Provisioning dispatches the CreateDatabase / MigrateDatabase /
        // SeedTenantDatabase jobs to the queue — force them inline so the
        // tenant DB exists before the activate() step reads it.
        config(['queue.default' => 'sync']);

        $onboarding = app(OnboardingService::class);

        foreach ($this->stores as $store) {
            $domain = $onboarding->buildDomain($store['subdomain']);

            if (Domain::where('domain', $domain)->exists()) {
                $this->command?->warn(sprintf(
                    'skip %s (%s) — already provisioned',
                    $store['business_name'],
                    $domain,
                ));

                continue;
            }

            $type = BusinessType::where('slug', $store['type_slug'])->firstOrFail();

            $lead = Lead::create([
                'name' => $store['owner'],
                'business_name' => $store['business_name'],
                'business_type_id' => $type->id,
                'phone' => $store['phone'],
                'email' => $store['contact_email'],
                'subdomain' => $store['subdomain'],
                'city' => $store['city'],
                'status' => 'new',
                'metadata' => [
                    'plan' => 'standard',
                    'subscription_days' => 30,
                ],
            ]);

            $provisioned = $onboarding->provision($lead);

            $onboarding->activate($provisioned['activation']->token, [
                'name' => $store['admin_name'],
                'email' => $store['contact_email'],
                'username' => $store['username'],
                'password' => self::ADMIN_PASSWORD,
                'currency' => 'JOD',
                'tax_enabled' => true,
                'default_tax_rate' => 16,
                'tax_calculation_method' => 'exclusive',
            ]);

            $this->command?->info(sprintf(
                'provisioned %s -> %s (db: %s) - tenant login: %s / %s',
                $store['business_name'],
                $onboarding->storeUrl($domain),
                $provisioned['database'],
                $store['username'],
                self::ADMIN_PASSWORD,
            ));
        }
    }
}
