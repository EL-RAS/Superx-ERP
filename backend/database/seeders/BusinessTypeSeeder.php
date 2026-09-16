<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'slug' => 'supermarket_hypermarket',
                'name_en' => 'Supermarket & Hypermarket',
                'name_ar' => 'سوبرماركت وهايبرماركت',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'barcode_scanner', 'expiry_tracking', 'weighing_scale', 'loyalty', 'promotions'],
                'default_settings' => [
                    'allow_split_payments' => true,
                    'allow_credit_sales' => false,
                    'expiry_alerts' => true,
                    'low_stock_sensitivity' => 'strict',
                    'barcode_scanner' => true,
                    'rapid_mode' => true,
                    'loyalty_enabled' => true,
                    'promotions_enabled' => true,
                ],
            ],
            [
                'slug' => 'clothing_apparel',
                'name_en' => 'Clothing & Apparel',
                'name_ar' => 'ملابس وأزياء',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'barcode_scanner', 'variant_matrix', 'loyalty', 'collections', 'returns_exchanges'],
                'default_settings' => [
                    'allow_split_payments' => true,
                    'allow_credit_sales' => false,
                    'expiry_alerts' => false,
                    'low_stock_sensitivity' => 'normal',
                    'barcode_scanner' => true,
                    'variant_matrix_enabled' => true,
                    'collections_enabled' => true,
                    'returns_exchanges_enabled' => true,
                ],
            ],
            [
                'slug' => 'electronics_warranty',
                'name_en' => 'Electronics & Warranty',
                'name_ar' => 'إلكترونيات وضمان',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'barcode_scanner', 'warranty_tracking', 'serial_numbers', 'service_tickets'],
            ],
            [
                'slug' => 'fast_food_kds',
                'name_en' => 'Fast Food / KDS',
                'name_ar' => 'وجبات سريعة',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'reports', 'kds', 'table_management', 'menu_management', 'recipe_costing'],
            ],
            [
                'slug' => 'fine_dining_reservations',
                'name_en' => 'Fine Dining & Reservations',
                'name_ar' => 'مطاعم فاخرة وحجوزات',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'reports', 'crm', 'kds', 'table_management', 'menu_management', 'recipe_costing', 'reservations'],
            ],
            [
                'slug' => 'dental_charting',
                'name_en' => 'Dental Clinic',
                'name_ar' => 'عيادة أسنان',
                'allowed_modules' => ['sales', 'accounting', 'users', 'reports', 'appointment_scheduling', 'emr', 'dental_charting', 'treatment_plans', 'insurance_billing'],
            ],
            [
                'slug' => 'general_clinic',
                'name_en' => 'General Medical Clinic',
                'name_ar' => 'عيادة طبية عامة',
                'allowed_modules' => ['sales', 'accounting', 'users', 'reports', 'appointment_scheduling', 'emr', 'insurance_billing', 'prescriptions', 'lab_orders'],
            ],
            [
                'slug' => 'jewelry_store',
                'name_en' => 'Jewelry Store',
                'name_ar' => 'محل مجوهرات',
                'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports', 'jewelry_tracking', 'gold_rate', 'weight_management', 'melting', 'repair_tickets', 'police_book', 'consignment'],
            ],
        ];

        foreach ($types as $type) {
            BusinessType::updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );
        }
    }
}
