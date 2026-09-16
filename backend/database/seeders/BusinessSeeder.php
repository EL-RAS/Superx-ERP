<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\DentalChart;
use App\Models\GoldRateLog;
use App\Models\InsuranceClaim;
use App\Models\KitchenOrder;
use App\Models\MedicalRecord;
use App\Models\PoliceBookEntry;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RepairTicket;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\SerialNumber;
use App\Models\Supplier;
use App\Models\TreatmentPlan;
use App\Models\TreatmentProcedure;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');
        $supermarketType = BusinessType::where('slug', 'supermarket_hypermarket')->firstOrFail();
        $clothingType = BusinessType::where('slug', 'clothing_apparel')->firstOrFail();
        $electronicsType = BusinessType::where('slug', 'electronics_warranty')->firstOrFail();
        $fastFoodType = BusinessType::where('slug', 'fast_food_kds')->firstOrFail();
        $fineDiningType = BusinessType::where('slug', 'fine_dining_reservations')->firstOrFail();
        $dentalType = BusinessType::where('slug', 'dental_charting')->firstOrFail();
        $generalClinicType = BusinessType::where('slug', 'general_clinic')->firstOrFail();
        $jewelryType = BusinessType::where('slug', 'jewelry_store')->firstOrFail();
        // -----------------------------------------------------------------------------------------------------------------
        // 1. SUPER RETAIL (Supermarket)
        // -----------------------------------------------------------------------------------------------------------------
        $biz1 = $this->resolveBusiness($supermarketType, 'Super Retail', 'super-retail');
        $admin1 = $this->resolveAdmin($biz1, 'Retail Admin', 'admin@superretail.com', 'retail_admin', $password);
        $srMilk = Product::create([
            'business_id' => $biz1->id,
            'created_by' => $admin1->id,
            'name' => 'Organic Whole Milk 1L',
            'sku' => 'SUP-MLK-001',
            'barcode' => '6221000001001',
            'price' => 32.50,
            'cost' => 0.11,
            'min_stock' => 50,
            'has_batch' => true,
            'is_active' => true,
            'metadata' => ['expiry_date' => '2026-09-15', 'reorder_point' => 50, 'department' => 'Dairy'],
        ]);
        $srBread = Product::create([
            'business_id' => $biz1->id,
            'created_by' => $admin1->id,
            'name' => 'Fresh White Bread',
            'sku' => 'SUP-BRD-002',
            'barcode' => '6221000001002',
            'price' => 18.00,
            'cost' => 0.06,
            'min_stock' => 30,
            'has_batch' => true,
            'is_active' => true,
            'metadata' => ['expiry_date' => '2026-07-20', 'reorder_point' => 30, 'department' => 'Bakery'],
        ]);
        $srChicken = Product::create([
            'business_id' => $biz1->id,
            'created_by' => $admin1->id,
            'name' => 'Chicken Breast 1kg',
            'sku' => 'SUP-CHK-003',
            'barcode' => '6221000001003',
            'price' => 145.00,
            'cost' => 1.38,
            'min_stock' => 20,
            'has_batch' => true,
            'is_active' => true,
            'metadata' => ['expiry_date' => '2026-07-18', 'reorder_point' => 20, 'department' => 'Meat'],
        ]);
        $srRice = Product::create([
            'business_id' => $biz1->id,
            'created_by' => $admin1->id,
            'name' => 'Basmati Rice 5kg',
            'sku' => 'SUP-RCE-004',
            'barcode' => '6221000001004',
            'price' => 185.00,
            'cost' => 1.17,
            'min_stock' => 40,
            'has_batch' => true,
            'is_active' => true,
            'metadata' => ['expiry_date' => '2027-01-01', 'reorder_point' => 40, 'department' => 'Grains'],
        ]);
        $srPepsi = Product::create([
            'business_id' => $biz1->id,
            'created_by' => $admin1->id,
            'name' => 'Pepsi Can 330ml',
            'sku' => 'SUP-PEP-005',
            'barcode' => '6221000001005',
            'price' => 12.00,
            'cost' => 0.01,
            'min_stock' => 100,
            'has_batch' => true,
            'is_active' => true,
            'metadata' => ['expiry_date' => '2026-12-31', 'reorder_point' => 100, 'department' => 'Beverages'],
        ]);
        $srCust1 = Customer::create([
            'business_id' => $biz1->id,
            'type' => 'customer',
            'name' => 'Ahmed Hassan',
            'phone' => '+201012345678',
            'email' => null,
            'address' => '15 Nile St, Cairo',
            'metadata' => [],
        ]);
        $srCust2 = Customer::create([
            'business_id' => $biz1->id,
            'type' => 'customer',
            'name' => 'Fatima Ali',
            'phone' => '+201098765432',
            'email' => null,
            'address' => '22 Tahrir Sq, Cairo',
            'metadata' => [],
        ]);
        $srCust3 = Customer::create([
            'business_id' => $biz1->id,
            'type' => 'customer',
            'name' => 'Mohamed Saeed',
            'phone' => '+201055512345',
            'email' => null,
            'address' => '8 Maadi Rd, Cairo',
            'metadata' => [],
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srMilk->id,
            'batch_number' => 'MLK-B-20260915',
            'quantity' => 200,
            'quantity_sold' => 200,
            'expiry_date' => '2026-09-15',
            'manufacturing_date' => '2026-06-15',
            'total_cost' => 22.00,
            'cost_per_unit' => 0.11,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srBread->id,
            'batch_number' => 'BRD-B-20260720',
            'quantity' => 150,
            'expiry_date' => '2026-07-20',
            'manufacturing_date' => '2026-07-17',
            'total_cost' => 9.00,
            'cost_per_unit' => 0.06,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srBread->id,
            'batch_number' => 'BRD-B-20261231',
            'quantity' => 10,
            'expiry_date' => '2026-12-31',
            'manufacturing_date' => '2026-07-31',
            'total_cost' => 0.60,
            'cost_per_unit' => 0.06,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srChicken->id,
            'batch_number' => 'CHK-B-20260718',
            'quantity' => 80,
            'expiry_date' => '2026-07-18',
            'manufacturing_date' => '2026-07-16',
            'total_cost' => 110.40,
            'cost_per_unit' => 1.38,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srChicken->id,
            'batch_number' => 'CHK-B-20261231',
            'quantity' => 5,
            'expiry_date' => '2026-12-31',
            'manufacturing_date' => '2026-07-31',
            'total_cost' => 6.90,
            'cost_per_unit' => 1.38,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srRice->id,
            'batch_number' => 'RCE-B-20270101',
            'quantity' => 120,
            'expiry_date' => '2027-01-01',
            'manufacturing_date' => '2026-03-01',
            'total_cost' => 140.40,
            'cost_per_unit' => 1.17,
            'is_active' => true,
        ]);
        ProductBatch::create([
            'business_id' => $biz1->id,
            'product_id' => $srPepsi->id,
            'batch_number' => 'PEP-B-20261231',
            'quantity' => 500,
            'expiry_date' => '2026-12-31',
            'manufacturing_date' => '2026-01-10',
            'total_cost' => 5.00,
            'cost_per_unit' => 0.01,
            'is_active' => true,
        ]);
        foreach ([$srMilk, $srBread, $srChicken, $srRice, $srPepsi] as $p) {
            $p->fresh()->recalculateStockQuantity();
            $p->fresh()->updateWeightedAverageCost();
            $p->fresh()->autoPrice();
        }
        echo 'Super Retail: Milk(OUT='.$srMilk->fresh()->stock_quantity.'), Chicken(LOW='.$srChicken->fresh()->stock_quantity.'), Bread(LOW='.$srBread->fresh()->stock_quantity.")\n";
        Supplier::create([
            'business_id' => $biz1->id,
            'name' => 'Fresh Dairy Co',
            'contact_name' => 'Hany Mansour',
            'email' => 'hany@freshdairy.com',
            'phone' => '+201023456789',
            'address' => 'Industrial Zone, Giza',
            'payment_terms' => 'Net 30',
            'is_active' => true,
        ]);
        Supplier::create([
            'business_id' => $biz1->id,
            'name' => 'Local Bakery Supplies',
            'contact_name' => 'Samir Younis',
            'email' => 'samir@localbakery.com',
            'phone' => '+201034567890',
            'address' => 'Bakery District, Alexandria',
            'payment_terms' => 'Net 15',
            'is_active' => true,
        ]);
        Supplier::create([
            'business_id' => $biz1->id,
            'name' => 'National Meat Trading',
            'contact_name' => 'Gamal Ibrahim',
            'email' => 'gamal@nationalmeat.com',
            'phone' => '+201045678901',
            'address' => 'Abbasia, Cairo',
            'payment_terms' => 'Cash on Delivery',
            'is_active' => true,
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 2. FASHION HUB (Clothing & Apparel)
        // -----------------------------------------------------------------------------------------------------------------
        $biz2 = $this->resolveBusiness($clothingType, 'Fashion Hub', 'fashion-hub');
        $admin2 = $this->resolveAdmin($biz2, 'Fashion Admin', 'admin@fashionhub.com', 'fashion_admin', $password);
        $colSummer = Collection::create([
            'business_id' => $biz2->id,
            'name' => 'Summer 2026',
            'season' => 'Summer',
            'year' => 2026,
            'description' => 'Lightweight fabrics and vibrant colors for the summer season.',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-31',
            'is_active' => true,
        ]);
        $colCapsule = Collection::create([
            'business_id' => $biz2->id,
            'name' => 'Capsule Essentials',
            'season' => 'All',
            'year' => 2026,
            'description' => 'Timeless wardrobe staples designed for year-round wear.',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        $fhShirt = Product::create([
            'business_id' => $biz2->id,
            'created_by' => $admin2->id,
            'collection_id' => $colSummer->id,
            'name' => 'Classic Oxford Shirt',
            'sku' => 'CLT-SHT-001',
            'barcode' => null,
            'price' => 850.00,
            'cost' => 320.00,
            'is_active' => true,
            'metadata' => ['color' => 'Royal Blue', 'size' => 'M', 'collection' => 'Summer 2026'],
        ]);
        $fhJeans = Product::create([
            'business_id' => $biz2->id,
            'created_by' => $admin2->id,
            'collection_id' => $colCapsule->id,
            'name' => 'Slim Fit Jeans',
            'sku' => 'CLT-JNS-002',
            'barcode' => null,
            'price' => 1200.00,
            'cost' => 450.00,
            'is_active' => true,
            'metadata' => ['color' => 'Indigo', 'size' => '32'],
        ]);
        $fhDress = Product::create([
            'business_id' => $biz2->id,
            'created_by' => $admin2->id,
            'collection_id' => $colSummer->id,
            'name' => 'Summer Floral Dress',
            'sku' => 'CLT-DRS-003',
            'barcode' => null,
            'price' => 1500.00,
            'cost' => 500.00,
            'is_active' => true,
            'metadata' => ['color' => 'Coral', 'size' => 'S'],
        ]);
        $fhBlazer = Product::create([
            'business_id' => $biz2->id,
            'created_by' => $admin2->id,
            'collection_id' => $colCapsule->id,
            'name' => 'Wool Blend Blazer',
            'sku' => 'CLT-BLZ-004',
            'barcode' => null,
            'price' => 2800.00,
            'cost' => 900.00,
            'is_active' => true,
            'metadata' => ['color' => 'Charcoal', 'size' => 'L'],
        ]);
        $fhBag = Product::create([
            'business_id' => $biz2->id,
            'created_by' => $admin2->id,
            'name' => 'Leather Crossbody Bag',
            'sku' => 'CLT-BAG-005',
            'barcode' => null,
            'price' => 950.00,
            'cost' => 280.00,
            'is_active' => true,
            'metadata' => ['color' => 'Tan', 'size' => 'N/A'],
        ]);
        $sizes = ['S', 'M', 'L', 'XL'];
        $colors = ['Blue', 'White', 'Black'];
        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                ProductVariant::create([
                    'business_id' => $biz2->id,
                    'product_id' => $fhShirt->id,
                    'sku' => "CLT-SHT-001-{$color}-{$size}",
                    'attribute1_name' => 'Size',
                    'attribute1_value' => $size,
                    'attribute2_name' => 'Color',
                    'attribute2_value' => $color,
                    'stock_quantity' => rand(5, 30),
                    'is_active' => true,
                ]);
            }
        }
        foreach (['30', '32', '34'] as $size) {
            ProductVariant::create([
                'business_id' => $biz2->id,
                'product_id' => $fhJeans->id,
                'sku' => "CLT-JNS-002-Indigo-{$size}",
                'attribute1_name' => 'Waist',
                'attribute1_value' => $size,
                'attribute2_name' => 'Color',
                'attribute2_value' => 'Indigo',
                'stock_quantity' => $size === '30' ? 15 : ($size === '32' ? 22 : 10),
                'is_active' => true,
            ]);
        }
        $fhCust1 = Customer::create([
            'business_id' => $biz2->id,
            'type' => 'customer',
            'name' => 'Sara Ibrahim',
            'phone' => '+201061112233',
            'email' => 'sara.ibrahim@email.com',
            'metadata' => ['loyalty_tier' => 'gold', 'preferred_size' => 'S'],
        ]);
        $fhCust2 = Customer::create([
            'business_id' => $biz2->id,
            'type' => 'customer',
            'name' => 'Layla Mahmoud',
            'phone' => '+201062223344',
            'email' => 'layla.mahmoud@email.com',
            'metadata' => ['loyalty_tier' => 'silver', 'preferred_size' => 'M'],
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 3. TECHZONE (Electronics & Warranty)
        // -----------------------------------------------------------------------------------------------------------------
        $biz3 = $this->resolveBusiness($electronicsType, 'TechZone', 'techzone');
        $admin3 = $this->resolveAdmin($biz3, 'Tech Admin', 'admin@techzone.com', 'tech_admin', $password);
        $tzSamsung = Product::create([
            'business_id' => $biz3->id,
            'created_by' => $admin3->id,
            'name' => 'Samsung Galaxy S25',
            'sku' => 'ELC-PHN-001',
            'barcode' => '8801234567890',
            'price' => 35000.00,
            'cost' => 28000.00,
            'is_active' => true,
            'metadata' => ['warranty_months' => 24, 'category' => 'Smartphones', 'brand' => 'Samsung'],
        ]);
        $tzIphone = Product::create([
            'business_id' => $biz3->id,
            'created_by' => $admin3->id,
            'name' => 'iPhone 16 Pro',
            'sku' => 'ELC-PHN-002',
            'barcode' => '8801234567891',
            'price' => 52000.00,
            'cost' => 44000.00,
            'is_active' => true,
            'metadata' => ['warranty_months' => 12, 'category' => 'Smartphones', 'brand' => 'Apple'],
        ]);
        $tzMacbook = Product::create([
            'business_id' => $biz3->id,
            'created_by' => $admin3->id,
            'name' => 'MacBook Air M3',
            'sku' => 'ELC-LPT-003',
            'barcode' => '8801234567892',
            'price' => 65000.00,
            'cost' => 55000.00,
            'is_active' => true,
            'metadata' => ['warranty_months' => 12, 'category' => 'Laptops', 'brand' => 'Apple'],
        ]);
        $tzSony = Product::create([
            'business_id' => $biz3->id,
            'created_by' => $admin3->id,
            'name' => 'Sony WH-1000XM5',
            'sku' => 'ELC-AUD-004',
            'barcode' => '8801234567893',
            'price' => 12500.00,
            'cost' => 8500.00,
            'is_active' => true,
            'metadata' => ['warranty_months' => 6, 'category' => 'Audio', 'brand' => 'Sony'],
        ]);
        $tzIpad = Product::create([
            'business_id' => $biz3->id,
            'created_by' => $admin3->id,
            'name' => 'iPad Air',
            'sku' => 'ELC-TAB-005',
            'barcode' => '8801234567894',
            'price' => 22000.00,
            'cost' => 18000.00,
            'is_active' => true,
            'metadata' => ['warranty_months' => 12, 'category' => 'Tablets', 'brand' => 'Apple'],
        ]);
        $tzCust1 = Customer::create([
            'business_id' => $biz3->id,
            'type' => 'customer',
            'name' => 'Khaled Omar',
            'phone' => '+201071112233',
            'email' => 'khaled.omar@email.com',
            'metadata' => [],
        ]);
        $tzCust2 = Customer::create([
            'business_id' => $biz3->id,
            'type' => 'customer',
            'name' => 'Youssef Nabil',
            'phone' => '+201072223344',
            'email' => 'youssef.nabil@email.com',
            'metadata' => [],
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzSamsung->id,
            'serial_number' => 'SN-SAM-G25-001',
            'imei' => '353456789012345',
            'warranty_start' => '2026-03-01',
            'warranty_end' => '2028-03-01',
            'warranty_status' => 'active',
            'status' => 'in_stock',
            'customer_id' => null,
            'sold_at' => null,
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzSamsung->id,
            'serial_number' => 'SN-SAM-G25-002',
            'imei' => '353456789012346',
            'warranty_start' => '2026-04-15',
            'warranty_end' => '2028-04-15',
            'warranty_status' => 'active',
            'status' => 'sold',
            'customer_id' => $tzCust1->id,
            'sold_at' => '2026-04-15 14:30:00',
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzIphone->id,
            'serial_number' => 'SN-APL-16P-001',
            'imei' => '353456789056789',
            'warranty_start' => '2026-06-01',
            'warranty_end' => '2027-06-01',
            'warranty_status' => 'active',
            'status' => 'sold',
            'customer_id' => $tzCust2->id,
            'sold_at' => '2026-06-10 11:00:00',
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzIphone->id,
            'serial_number' => 'SN-APL-16P-002',
            'imei' => '353456789056790',
            'warranty_start' => '2026-06-20',
            'warranty_end' => '2027-06-20',
            'warranty_status' => 'active',
            'status' => 'in_stock',
            'customer_id' => null,
            'sold_at' => null,
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzMacbook->id,
            'serial_number' => 'SN-APL-MA3-001',
            'imei' => null,
            'warranty_start' => '2026-02-10',
            'warranty_end' => '2027-02-10',
            'warranty_status' => 'active',
            'status' => 'in_stock',
            'customer_id' => null,
            'sold_at' => null,
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzSony->id,
            'serial_number' => 'SN-SNY-XM5-001',
            'imei' => null,
            'warranty_start' => '2026-05-01',
            'warranty_end' => '2026-11-01',
            'warranty_status' => 'active',
            'status' => 'sold',
            'customer_id' => $tzCust1->id,
            'sold_at' => '2026-05-20 16:45:00',
        ]);
        SerialNumber::create([
            'business_id' => $biz3->id,
            'product_id' => $tzIpad->id,
            'serial_number' => 'SN-APL-IPA-001',
            'imei' => '353456789099999',
            'warranty_start' => '2026-07-01',
            'warranty_end' => '2027-07-01',
            'warranty_status' => 'active',
            'status' => 'in_stock',
            'customer_id' => null,
            'sold_at' => null,
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 4. BYTE BURGER (Fast Food / KDS)
        // -----------------------------------------------------------------------------------------------------------------
        $biz4 = $this->resolveBusiness($fastFoodType, 'Byte Burger', 'byte-burger');
        $admin4 = $this->resolveAdmin($biz4, 'Byte Burger Admin', 'admin@byteburger.com', 'burger_admin', $password);
        $bbClassic = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Classic Smash Burger',
            'sku' => 'RST-BRG-001',
            'barcode' => null,
            'price' => 145.00,
            'cost' => 52.00,
            'is_active' => true,
            'metadata' => ['is_spicy' => false, 'prep_time_minutes' => 8, 'category' => 'Burgers'],
        ]);
        $bbSpicy = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Spicy Inferno Burger',
            'sku' => 'RST-BRG-002',
            'barcode' => null,
            'price' => 165.00,
            'cost' => 58.00,
            'is_active' => true,
            'metadata' => ['is_spicy' => true, 'prep_time_minutes' => 10, 'category' => 'Burgers'],
        ]);
        $bbChicken = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Crispy Chicken Sandwich',
            'sku' => 'RST-CHI-003',
            'barcode' => null,
            'price' => 155.00,
            'cost' => 55.00,
            'is_active' => true,
            'metadata' => ['is_spicy' => false, 'prep_time_minutes' => 12, 'category' => 'Chicken'],
        ]);
        $bbFries = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Loaded Fries',
            'sku' => 'RST-FRY-004',
            'barcode' => null,
            'price' => 85.00,
            'cost' => 25.00,
            'is_active' => true,
            'metadata' => ['is_spicy' => true, 'prep_time_minutes' => 6, 'category' => 'Sides'],
        ]);
        $bbDrink = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Fresh Lemon Mint',
            'sku' => 'RST-DRK-005',
            'barcode' => null,
            'price' => 45.00,
            'cost' => 8.00,
            'is_active' => true,
            'metadata' => ['is_spicy' => false, 'prep_time_minutes' => 3, 'category' => 'Drinks'],
        ]);
        $bbBeef = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Beef Patty',
            'sku' => 'RST-ING-010',
            'barcode' => null,
            'price' => 0,
            'cost' => 18.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbBun = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Burger Bun',
            'sku' => 'RST-ING-011',
            'barcode' => null,
            'price' => 0,
            'cost' => 3.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbLettuce = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Lettuce Leaf',
            'sku' => 'RST-ING-012',
            'barcode' => null,
            'price' => 0,
            'cost' => 0.50,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbTomato = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Tomato Slice',
            'sku' => 'RST-ING-013',
            'barcode' => null,
            'price' => 0,
            'cost' => 0.80,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbCheese = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Cheddar Cheese Slice',
            'sku' => 'RST-ING-014',
            'barcode' => null,
            'price' => 0,
            'cost' => 2.50,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbChickenBreast = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Chicken Breast Fillet',
            'sku' => 'RST-ING-015',
            'barcode' => null,
            'price' => 0,
            'cost' => 20.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbPotato = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'French Fries Potato',
            'sku' => 'RST-ING-016',
            'barcode' => null,
            'price' => 0,
            'cost' => 5.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbCheeseSauce = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Cheese Sauce',
            'sku' => 'RST-ING-017',
            'barcode' => null,
            'price' => 0,
            'cost' => 4.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbLemon = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Fresh Lemon',
            'sku' => 'RST-ING-018',
            'barcode' => null,
            'price' => 0,
            'cost' => 2.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbMint = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Fresh Mint',
            'sku' => 'RST-ING-019',
            'barcode' => null,
            'price' => 0,
            'cost' => 1.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $bbBbqSauce = Product::create([
            'business_id' => $biz4->id,
            'created_by' => $admin4->id,
            'name' => 'Spicy BBQ Sauce',
            'sku' => 'RST-ING-020',
            'barcode' => null,
            'price' => 0,
            'cost' => 3.00,
            'is_active' => true,
            'metadata' => ['type' => 'ingredient'],
        ]);
        $recipeClassic = Recipe::create([
            'business_id' => $biz4->id,
            'product_id' => $bbClassic->id,
            'name' => 'Classic Smash Burger Recipe',
            'serving_size' => '1 burger',
            'prep_time_minutes' => 8,
            'cost_per_serving' => 52.00,
            'instructions' => 'Grill beef patty for 4 min each side. Toast bun. Assemble with lettuce, tomato, cheese.',
            'is_active' => true,
        ]);
        foreach ([
            ['product_id' => $bbBeef->id, 'quantity' => 1, 'unit' => 'patty', 'cost_per_unit' => 18.00],
            ['product_id' => $bbBun->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 3.00],
            ['product_id' => $bbLettuce->id, 'quantity' => 1, 'unit' => 'leaf', 'cost_per_unit' => 0.50],
            ['product_id' => $bbTomato->id, 'quantity' => 2, 'unit' => 'slices', 'cost_per_unit' => 0.80],
            ['product_id' => $bbCheese->id, 'quantity' => 1, 'unit' => 'slice', 'cost_per_unit' => 2.50],
        ] as $ing) {
            RecipeIngredient::create(array_merge($ing, [
                'business_id' => $biz4->id,
                'recipe_id' => $recipeClassic->id,
            ]));
        }
        $recipeSpicy = Recipe::create([
            'business_id' => $biz4->id,
            'product_id' => $bbSpicy->id,
            'name' => 'Spicy Inferno Burger Recipe',
            'serving_size' => '1 burger',
            'prep_time_minutes' => 10,
            'cost_per_serving' => 58.00,
            'instructions' => 'Grill beef patty with spicy rub for 4 min each side. Toast bun. Spread spicy sauce. Assemble.',
            'is_active' => true,
        ]);
        foreach ([
            ['product_id' => $bbBeef->id, 'quantity' => 1, 'unit' => 'patty', 'cost_per_unit' => 18.00],
            ['product_id' => $bbBun->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 3.00],
            ['product_id' => $bbLettuce->id, 'quantity' => 1, 'unit' => 'leaf', 'cost_per_unit' => 0.50],
            ['product_id' => $bbTomato->id, 'quantity' => 2, 'unit' => 'slices', 'cost_per_unit' => 0.80],
            ['product_id' => $bbCheese->id, 'quantity' => 1, 'unit' => 'slice', 'cost_per_unit' => 2.50],
            ['product_id' => $bbBbqSauce->id, 'quantity' => 2, 'unit' => 'tbsp', 'cost_per_unit' => 3.00],
        ] as $ing) {
            RecipeIngredient::create(array_merge($ing, [
                'business_id' => $biz4->id,
                'recipe_id' => $recipeSpicy->id,
            ]));
        }
        $recipeChicken = Recipe::create([
            'business_id' => $biz4->id,
            'product_id' => $bbChicken->id,
            'name' => 'Crispy Chicken Sandwich Recipe',
            'serving_size' => '1 sandwich',
            'prep_time_minutes' => 12,
            'cost_per_serving' => 55.00,
            'instructions' => 'Bread and deep-fry chicken breast for 6 min. Toast bun. Assemble with mayo and lettuce.',
            'is_active' => true,
        ]);
        foreach ([
            ['product_id' => $bbChickenBreast->id, 'quantity' => 1, 'unit' => 'fillet', 'cost_per_unit' => 20.00],
            ['product_id' => $bbBun->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 3.00],
            ['product_id' => $bbLettuce->id, 'quantity' => 1, 'unit' => 'leaf', 'cost_per_unit' => 0.50],
        ] as $ing) {
            RecipeIngredient::create(array_merge($ing, [
                'business_id' => $biz4->id,
                'recipe_id' => $recipeChicken->id,
            ]));
        }
        $recipeFries = Recipe::create([
            'business_id' => $biz4->id,
            'product_id' => $bbFries->id,
            'name' => 'Loaded Fries Recipe',
            'serving_size' => '1 portion',
            'prep_time_minutes' => 6,
            'cost_per_serving' => 25.00,
            'instructions' => 'Deep-fry potato strips for 4 min. Top with cheese sauce, jalapenos, and bacon bits.',
            'is_active' => true,
        ]);
        foreach ([
            ['product_id' => $bbPotato->id, 'quantity' => 200, 'unit' => 'grams', 'cost_per_unit' => 5.00],
            ['product_id' => $bbCheeseSauce->id, 'quantity' => 2, 'unit' => 'tbsp', 'cost_per_unit' => 4.00],
        ] as $ing) {
            RecipeIngredient::create(array_merge($ing, [
                'business_id' => $biz4->id,
                'recipe_id' => $recipeFries->id,
            ]));
        }
        $recipeDrink = Recipe::create([
            'business_id' => $biz4->id,
            'product_id' => $bbDrink->id,
            'name' => 'Fresh Lemon Mint Recipe',
            'serving_size' => '1 glass (350ml)',
            'prep_time_minutes' => 3,
            'cost_per_serving' => 8.00,
            'instructions' => 'Muddle mint. Add lemon juice, sugar, and ice. Top with sparkling water.',
            'is_active' => true,
        ]);
        foreach ([
            ['product_id' => $bbLemon->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 2.00],
            ['product_id' => $bbMint->id, 'quantity' => 5, 'unit' => 'leaves', 'cost_per_unit' => 1.00],
        ] as $ing) {
            RecipeIngredient::create(array_merge($ing, [
                'business_id' => $biz4->id,
                'recipe_id' => $recipeDrink->id,
            ]));
        }
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => '1',
            'section_id' => 'main-hall',
            'seats' => 4,
            'shape' => 'square',
            'status' => 'available',
            'position_x' => 100,
            'position_y' => 100,
        ]);
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => '2',
            'section_id' => 'main-hall',
            'seats' => 4,
            'shape' => 'square',
            'status' => 'occupied',
            'position_x' => 200,
            'position_y' => 100,
            'occupied_since' => now()->subMinutes(15),
        ]);
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => '3',
            'section_id' => 'main-hall',
            'seats' => 6,
            'shape' => 'rectangle',
            'status' => 'available',
            'position_x' => 300,
            'position_y' => 100,
        ]);
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => '4',
            'section_id' => 'main-hall',
            'seats' => 2,
            'shape' => 'round',
            'status' => 'available',
            'position_x' => 100,
            'position_y' => 200,
        ]);
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => 'T1',
            'section_id' => 'terrace',
            'seats' => 4,
            'shape' => 'square',
            'status' => 'available',
            'position_x' => 500,
            'position_y' => 100,
        ]);
        RestaurantTable::create([
            'business_id' => $biz4->id,
            'number' => 'T2',
            'section_id' => 'terrace',
            'seats' => 6,
            'shape' => 'rectangle',
            'status' => 'occupied',
            'position_x' => 600,
            'position_y' => 100,
            'occupied_since' => now()->subMinutes(25),
        ]);
        KitchenOrder::create([
            'business_id' => $biz4->id,
            'user_id' => $admin4->id,
            'order_number' => 'KB-1001',
            'table_number' => '2',
            'order_type' => 'dine_in',
            'status' => 'preparing',
            'priority' => 1,
            'notes' => 'Extra cheese on classic burger',
            'items' => [
                ['name' => 'Classic Smash Burger', 'qty' => 2, 'status' => 'preparing'],
                ['name' => 'Loaded Fries', 'qty' => 1, 'status' => 'ready'],
            ],
            'station' => 'grill',
            'started_at' => now()->subMinutes(5),
        ]);
        KitchenOrder::create([
            'business_id' => $biz4->id,
            'user_id' => $admin4->id,
            'order_number' => 'KB-1002',
            'table_number' => 'T2',
            'order_type' => 'dine_in',
            'status' => 'ready',
            'priority' => 1,
            'notes' => null,
            'items' => [
                ['name' => 'Crispy Chicken Sandwich', 'qty' => 1, 'status' => 'ready'],
                ['name' => 'Fresh Lemon Mint', 'qty' => 2, 'status' => 'ready'],
            ],
            'station' => 'fryer',
            'started_at' => now()->subMinutes(12),
            'ready_at' => now()->subMinutes(2),
        ]);
        KitchenOrder::create([
            'business_id' => $biz4->id,
            'user_id' => $admin4->id,
            'order_number' => 'KB-1003',
            'table_number' => null,
            'order_type' => 'takeaway',
            'status' => 'new',
            'priority' => 2,
            'notes' => 'No lettuce',
            'items' => [
                ['name' => 'Spicy Inferno Burger', 'qty' => 1, 'status' => 'new'],
                ['name' => 'Loaded Fries', 'qty' => 2, 'status' => 'new'],
                ['name' => 'Fresh Lemon Mint', 'qty' => 1, 'status' => 'new'],
            ],
            'station' => 'grill',
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 5. LE CIEL (Fine Dining & Reservations)
        // -----------------------------------------------------------------------------------------------------------------
        $biz5 = $this->resolveBusiness($fineDiningType, 'Le Ciel', 'le-ciel');
        $admin5 = $this->resolveAdmin($biz5, 'Le Ciel Admin', 'admin@leciel.com', 'leciel_admin', $password);
        $lcWagyu = Product::create([
            'business_id' => $biz5->id,
            'created_by' => $admin5->id,
            'name' => 'Wagyu Steak',
            'sku' => 'RST-WAG-001',
            'barcode' => null,
            'price' => 850.00,
            'cost' => 350.00,
            'is_active' => true,
            'metadata' => ['category' => 'Main Courses', 'prep_time_minutes' => 20, 'is_spicy' => false],
        ]);
        $lcLobster = Product::create([
            'business_id' => $biz5->id,
            'created_by' => $admin5->id,
            'name' => 'Lobster Thermidor',
            'sku' => 'RST-LOB-002',
            'barcode' => null,
            'price' => 650.00,
            'cost' => 280.00,
            'is_active' => true,
            'metadata' => ['category' => 'Main Courses', 'prep_time_minutes' => 25, 'is_spicy' => false],
        ]);
        $lcRisotto = Product::create([
            'business_id' => $biz5->id,
            'created_by' => $admin5->id,
            'name' => 'Truffle Risotto',
            'sku' => 'RST-RIS-003',
            'barcode' => null,
            'price' => 320.00,
            'cost' => 100.00,
            'is_active' => true,
            'metadata' => ['category' => 'Main Courses', 'prep_time_minutes' => 18, 'is_spicy' => false],
        ]);
        $lcTiramisu = Product::create([
            'business_id' => $biz5->id,
            'created_by' => $admin5->id,
            'name' => 'Tiramisu Classico',
            'sku' => 'RST-TIR-004',
            'barcode' => null,
            'price' => 180.00,
            'cost' => 45.00,
            'is_active' => true,
            'metadata' => ['category' => 'Desserts', 'prep_time_minutes' => 5, 'is_spicy' => false],
        ]);
        $lcChampagne = Product::create([
            'business_id' => $biz5->id,
            'created_by' => $admin5->id,
            'name' => 'Champagne Glass',
            'sku' => 'RST-CHM-005',
            'barcode' => null,
            'price' => 250.00,
            'cost' => 80.00,
            'is_active' => true,
            'metadata' => ['category' => 'Beverages', 'is_spicy' => false],
        ]);
        $lcWagyuIng = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Wagyu Beef 200g', 'sku' => 'RST-ING-W01', 'price' => 0, 'cost' => 200.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcHerbs = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Fresh Herb Mix', 'sku' => 'RST-ING-W02', 'price' => 0, 'cost' => 15.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcButter = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'French Butter', 'sku' => 'RST-ING-W03', 'price' => 0, 'cost' => 20.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcLobsterIng = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Fresh Lobster', 'sku' => 'RST-ING-L01', 'price' => 0, 'cost' => 180.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcCream = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Heavy Cream', 'sku' => 'RST-ING-L02', 'price' => 0, 'cost' => 15.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcRice = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Arborio Rice', 'sku' => 'RST-ING-R01', 'price' => 0, 'cost' => 12.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcTruffle = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Black Truffle Shavings', 'sku' => 'RST-ING-R02', 'price' => 0, 'cost' => 40.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $lcParmesan = Product::create(['business_id' => $biz5->id, 'created_by' => $admin5->id, 'name' => 'Parmesan Reggiano', 'sku' => 'RST-ING-R03', 'price' => 0, 'cost' => 18.00, 'is_active' => true, 'metadata' => ['type' => 'ingredient']]);
        $recipeWagyu = Recipe::create([
            'business_id' => $biz5->id,
            'product_id' => $lcWagyu->id,
            'name' => 'Wagyu Steak Recipe',
            'serving_size' => '1 portion',
            'prep_time_minutes' => 20,
            'cost_per_serving' => 350.00,
            'instructions' => 'Sear wagyu on high heat 3 min each side. Rest 5 min. Finish with herb butter.',
            'is_active' => true,
        ]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeWagyu->id, 'product_id' => $lcWagyuIng->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 200.00]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeWagyu->id, 'product_id' => $lcHerbs->id, 'quantity' => 10, 'unit' => 'grams', 'cost_per_unit' => 15.00]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeWagyu->id, 'product_id' => $lcButter->id, 'quantity' => 15, 'unit' => 'grams', 'cost_per_unit' => 20.00]);
        $recipeLobster = Recipe::create([
            'business_id' => $biz5->id,
            'product_id' => $lcLobster->id,
            'name' => 'Lobster Thermidor Recipe',
            'serving_size' => '1 portion',
            'prep_time_minutes' => 25,
            'cost_per_serving' => 280.00,
            'instructions' => 'Steam lobster 8 min. Remove meat. Sauté in cream sauce with cognac. Gratin under broiler.',
            'is_active' => true,
        ]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeLobster->id, 'product_id' => $lcLobsterIng->id, 'quantity' => 1, 'unit' => 'piece', 'cost_per_unit' => 180.00]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeLobster->id, 'product_id' => $lcCream->id, 'quantity' => 50, 'unit' => 'ml', 'cost_per_unit' => 15.00]);
        $recipeRisotto = Recipe::create([
            'business_id' => $biz5->id,
            'product_id' => $lcRisotto->id,
            'name' => 'Truffle Risotto Recipe',
            'serving_size' => '1 portion',
            'prep_time_minutes' => 18,
            'cost_per_serving' => 100.00,
            'instructions' => 'Toast arborio rice. Ladle warm broth gradually. Stir in parmesan and butter. Finish with truffle shavings.',
            'is_active' => true,
        ]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeRisotto->id, 'product_id' => $lcRice->id, 'quantity' => 80, 'unit' => 'grams', 'cost_per_unit' => 12.00]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeRisotto->id, 'product_id' => $lcTruffle->id, 'quantity' => 5, 'unit' => 'grams', 'cost_per_unit' => 40.00]);
        RecipeIngredient::create(['business_id' => $biz5->id, 'recipe_id' => $recipeRisotto->id, 'product_id' => $lcParmesan->id, 'quantity' => 20, 'unit' => 'grams', 'cost_per_unit' => 18.00]);
        $lcCust1 = Customer::create(['business_id' => $biz5->id, 'type' => 'customer', 'name' => 'Amr El-Masry', 'phone' => '+201081112233', 'email' => 'amr@email.com', 'metadata' => ['preferred_section' => 'VIP']]);
        $lcCust2 = Customer::create(['business_id' => $biz5->id, 'type' => 'customer', 'name' => 'Nadia Karim', 'phone' => '+201082223344', 'email' => 'nadia@email.com', 'metadata' => ['preferred_section' => 'Terrace']]);
        $lcCust3 = Customer::create(['business_id' => $biz5->id, 'type' => 'customer', 'name' => 'Tarek Mansour', 'phone' => '+201083334455', 'email' => 'tarek@email.com', 'metadata' => []]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => '1', 'section_id' => 'main-hall', 'seats' => 2, 'shape' => 'round', 'status' => 'available', 'position_x' => 100, 'position_y' => 100]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => '2', 'section_id' => 'main-hall', 'seats' => 4, 'shape' => 'square', 'status' => 'available', 'position_x' => 200, 'position_y' => 100]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => '3', 'section_id' => 'main-hall', 'seats' => 4, 'shape' => 'square', 'status' => 'occupied', 'position_x' => 300, 'position_y' => 100, 'occupied_since' => now()->subMinutes(30)]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => '4', 'section_id' => 'main-hall', 'seats' => 6, 'shape' => 'rectangle', 'status' => 'available', 'position_x' => 100, 'position_y' => 200]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => 'T1', 'section_id' => 'terrace', 'seats' => 2, 'shape' => 'round', 'status' => 'available', 'position_x' => 500, 'position_y' => 100]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => 'T2', 'section_id' => 'terrace', 'seats' => 4, 'shape' => 'square', 'status' => 'reserved', 'position_x' => 600, 'position_y' => 100]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => 'V1', 'section_id' => 'vip', 'seats' => 8, 'shape' => 'rectangle', 'status' => 'available', 'position_x' => 800, 'position_y' => 100]);
        RestaurantTable::create(['business_id' => $biz5->id, 'number' => 'V2', 'section_id' => 'vip', 'seats' => 6, 'shape' => 'rectangle', 'status' => 'available', 'position_x' => 900, 'position_y' => 100]);
        $lcTableT2 = RestaurantTable::where('business_id', $biz5->id)->where('number', 'T2')->first();
        $lcTable3 = RestaurantTable::where('business_id', $biz5->id)->where('number', '3')->first();
        Reservation::create([
            'business_id' => $biz5->id,
            'customer_id' => $lcCust1->id,
            'table_id' => $lcTableT2->id,
            'guest_name' => 'Amr El-Masry',
            'guest_phone' => '+201081112233',
            'party_size' => 4,
            'reservation_date' => now()->addDay(),
            'reservation_time' => '20:00',
            'duration_minutes' => 120,
            'status' => 'confirmed',
            'occasion' => 'Anniversary',
            'special_requests' => 'Window seat, red roses on table',
        ]);
        Reservation::create([
            'business_id' => $biz5->id,
            'customer_id' => $lcCust2->id,
            'table_id' => $lcTable3->id,
            'guest_name' => 'Nadia Karim',
            'guest_phone' => '+201082223344',
            'party_size' => 2,
            'reservation_date' => now(),
            'reservation_time' => '19:30',
            'duration_minutes' => 90,
            'status' => 'seated',
            'occasion' => null,
            'special_requests' => null,
        ]);
        Reservation::create([
            'business_id' => $biz5->id,
            'customer_id' => $lcCust3->id,
            'table_id' => null,
            'guest_name' => 'Tarek Mansour',
            'guest_phone' => '+201083334455',
            'party_size' => 6,
            'reservation_date' => now()->subDay(),
            'reservation_time' => '21:00',
            'duration_minutes' => 150,
            'status' => 'completed',
            'occasion' => 'Business Dinner',
            'special_requests' => 'Private area preferred',
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 6. SMILE DENTAL (Dental Clinic)
        // -----------------------------------------------------------------------------------------------------------------
        $biz6 = $this->resolveBusiness($dentalType, 'Smile Dental', 'smile-dental');
        $admin6 = $this->resolveAdmin($biz6, 'Dr. Aisha Noor', 'admin@smiledental.com', 'dental_admin', $password);
        $sdCleaning = Product::create([
            'business_id' => $biz6->id,
            'created_by' => $admin6->id,
            'name' => 'Dental Cleaning',
            'sku' => 'DNT-CLN-001',
            'barcode' => null,
            'price' => 500.00,
            'cost' => 50.00,
            'is_active' => true,
            'metadata' => ['category' => 'Preventive', 'duration_minutes' => 45],
        ]);
        $sdFilling = Product::create([
            'business_id' => $biz6->id,
            'created_by' => $admin6->id,
            'name' => 'Composite Filling',
            'sku' => 'DNT-FIL-002',
            'barcode' => null,
            'price' => 800.00,
            'cost' => 120.00,
            'is_active' => true,
            'metadata' => ['category' => 'Restorative', 'duration_minutes' => 60],
        ]);
        $sdWhitening = Product::create([
            'business_id' => $biz6->id,
            'created_by' => $admin6->id,
            'name' => 'Teeth Whitening',
            'sku' => 'DNT-WHT-003',
            'barcode' => null,
            'price' => 2000.00,
            'cost' => 300.00,
            'is_active' => true,
            'metadata' => ['category' => 'Cosmetic', 'duration_minutes' => 90],
        ]);
        $sdPatient1 = Customer::create([
            'business_id' => $biz6->id,
            'type' => 'patient',
            'name' => 'Omar Al-Farsi',
            'phone' => '+201091112233',
            'email' => 'omar.farsi@email.com',
            'metadata' => ['date_of_birth' => '1990-05-12', 'blood_type' => 'O+', 'allergies' => ['penicillin']],
        ]);
        $sdPatient2 = Customer::create([
            'business_id' => $biz6->id,
            'type' => 'patient',
            'name' => 'Nour Hassan',
            'phone' => '+201092223344',
            'email' => 'nour.hassan@email.com',
            'metadata' => ['date_of_birth' => '1985-11-20', 'blood_type' => 'A+', 'allergies' => []],
        ]);
        $sdPatient3 = Customer::create([
            'business_id' => $biz6->id,
            'type' => 'patient',
            'name' => 'Tariq bin Said',
            'phone' => '+201093334455',
            'email' => 'tariq.said@email.com',
            'metadata' => ['date_of_birth' => '1978-03-08', 'blood_type' => 'B-', 'allergies' => ['latex']],
        ]);
        $appt1 = Appointment::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient1->id,
            'user_id' => $admin6->id,
            'appointment_number' => 'APT-DNT-001',
            'appointment_date' => now()->addDay(),
            'appointment_time' => '10:00',
            'duration_minutes' => 45,
            'status' => 'scheduled',
            'reason' => 'Routine dental cleaning',
            'notes' => null,
        ]);
        $appt2 = Appointment::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient2->id,
            'user_id' => $admin6->id,
            'appointment_number' => 'APT-DNT-002',
            'appointment_date' => now()->subDays(3),
            'appointment_time' => '14:00',
            'duration_minutes' => 60,
            'status' => 'completed',
            'reason' => 'Toothache on upper right molar',
            'notes' => 'Filling placed on tooth #3',
        ]);
        $appt3 = Appointment::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient3->id,
            'user_id' => $admin6->id,
            'appointment_number' => 'APT-DNT-003',
            'appointment_date' => now(),
            'appointment_time' => '11:30',
            'duration_minutes' => 90,
            'status' => 'in_progress',
            'reason' => 'Teeth whitening consultation',
            'notes' => 'Patient consented, procedure started',
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient1->id,
            'tooth_number' => '16',
            'condition' => 'healthy',
            'surface' => null,
            'notes' => null,
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient1->id,
            'tooth_number' => '36',
            'condition' => 'healthy',
            'surface' => null,
            'notes' => null,
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient2->id,
            'tooth_number' => '3',
            'condition' => 'filled',
            'surface' => 'MO',
            'notes' => 'Composite filling placed',
            'last_treated_at' => now()->subDays(3),
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient2->id,
            'tooth_number' => '14',
            'condition' => 'decayed',
            'surface' => 'DO',
            'notes' => 'Deep caries, treatment planned',
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient2->id,
            'tooth_number' => '46',
            'condition' => 'healthy',
            'surface' => null,
            'notes' => null,
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient3->id,
            'tooth_number' => '21',
            'condition' => 'crown',
            'surface' => null,
            'notes' => 'Porcelain crown placed 2024',
        ]);
        DentalChart::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient3->id,
            'tooth_number' => '48',
            'condition' => 'extracted',
            'surface' => null,
            'notes' => 'Wisdom tooth extracted',
            'last_treated_at' => now()->subMonths(6),
        ]);
        $tp1 = TreatmentPlan::create([
            'business_id' => $biz6->id,
            'customer_id' => $sdPatient1->id,
            'appointment_id' => $appt1->id,
            'plan_number' => 'TP-DNT-001',
            'title' => 'Comprehensive Oral Care Plan',
            'status' => 'in_progress',
            'estimated_cost' => 1300.00,
            'actual_cost' => null,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'notes' => 'Patient requires cleaning + one filling',
        ]);
        TreatmentProcedure::create([
            'business_id' => $biz6->id,
            'treatment_plan_id' => $tp1->id,
            'tooth_number' => null,
            'procedure_code' => 'D1110',
            'procedure_name' => 'Dental Cleaning',
            'description' => 'Prophylaxis - full mouth scaling and polishing',
            'cost' => 500.00,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        TreatmentProcedure::create([
            'business_id' => $biz6->id,
            'treatment_plan_id' => $tp1->id,
            'tooth_number' => '14',
            'procedure_code' => 'D2391',
            'procedure_name' => 'Composite Filling',
            'description' => 'One-surface composite restoration on tooth #14',
            'cost' => 800.00,
            'status' => 'planned',
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 7. AL-SHIFA CLINIC (General Medical Clinic)
        // -----------------------------------------------------------------------------------------------------------------
        $biz7 = $this->resolveBusiness($generalClinicType, 'Al-Shifa Clinic', 'al-shifa-clinic');
        $admin7 = $this->resolveAdmin($biz7, 'Dr. Hassan Youssef', 'admin@alshifa.com', 'clinic_admin', $password);
        $asConsult = Product::create([
            'business_id' => $biz7->id,
            'created_by' => $admin7->id,
            'name' => 'General Consultation',
            'sku' => 'MED-CON-001',
            'barcode' => null,
            'price' => 300.00,
            'cost' => 30.00,
            'is_active' => true,
            'metadata' => ['category' => 'Consultation', 'duration_minutes' => 30],
        ]);
        $asBlood = Product::create([
            'business_id' => $biz7->id,
            'created_by' => $admin7->id,
            'name' => 'Blood Test Panel',
            'sku' => 'MED-BLD-002',
            'barcode' => null,
            'price' => 200.00,
            'cost' => 80.00,
            'is_active' => true,
            'metadata' => ['category' => 'Laboratory', 'duration_minutes' => 15],
        ]);
        $asXray = Product::create([
            'business_id' => $biz7->id,
            'created_by' => $admin7->id,
            'name' => 'X-Ray',
            'sku' => 'MED-XRY-003',
            'barcode' => null,
            'price' => 500.00,
            'cost' => 150.00,
            'is_active' => true,
            'metadata' => ['category' => 'Imaging', 'duration_minutes' => 20],
        ]);
        $asPatient1 = Customer::create([
            'business_id' => $biz7->id,
            'type' => 'patient',
            'name' => 'Mariam Farouk',
            'phone' => '+201101112233',
            'email' => 'mariam.farouk@email.com',
            'metadata' => ['date_of_birth' => '1988-07-14', 'blood_type' => 'A-', 'allergies' => ['aspirin']],
        ]);
        $asPatient2 = Customer::create([
            'business_id' => $biz7->id,
            'type' => 'patient',
            'name' => 'Ahmed El-Sayed',
            'phone' => '+201102223344',
            'email' => 'ahmed.elsayed@email.com',
            'metadata' => ['date_of_birth' => '1975-02-28', 'blood_type' => 'AB+', 'allergies' => []],
        ]);
        $asPatient3 = Customer::create([
            'business_id' => $biz7->id,
            'type' => 'patient',
            'name' => 'Hana Youssef',
            'phone' => '+201103334455',
            'email' => 'hana.youssef@email.com',
            'metadata' => ['date_of_birth' => '1995-12-01', 'blood_type' => 'O-', 'allergies' => ['ibuprofen']],
        ]);
        $asAppt1 = Appointment::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient1->id,
            'user_id' => $admin7->id,
            'appointment_number' => 'APT-GEN-001',
            'appointment_date' => now()->subDays(5),
            'appointment_time' => '09:00',
            'duration_minutes' => 30,
            'status' => 'completed',
            'reason' => 'Persistent headaches and fatigue',
        ]);
        $asAppt2 = Appointment::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient2->id,
            'user_id' => $admin7->id,
            'appointment_number' => 'APT-GEN-002',
            'appointment_date' => now()->subDays(2),
            'appointment_time' => '11:00',
            'duration_minutes' => 30,
            'status' => 'completed',
            'reason' => 'Annual checkup and blood work',
        ]);
        $asAppt3 = Appointment::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient3->id,
            'user_id' => $admin7->id,
            'appointment_number' => 'APT-GEN-003',
            'appointment_date' => now()->addDay(),
            'appointment_time' => '14:30',
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'reason' => 'Follow-up on blood test results',
        ]);
        $asRecord1 = MedicalRecord::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient1->id,
            'appointment_id' => $asAppt1->id,
            'user_id' => $admin7->id,
            'record_number' => 'MR-GEN-001',
            'subjective' => 'Patient reports daily headaches for the past 2 weeks, worsening in the afternoon. Also complains of chronic fatigue and difficulty concentrating at work.',
            'objective' => 'BP: 130/85 mmHg, HR: 78 bpm, Temp: 36.8°C, Weight: 68 kg, BMI: 24.2. General appearance: mild pallor. HEENT: normal fundoscopy, no meningeal signs.',
            'assessment' => 'Tension-type headache with possible anemia. Rule out hypertension stage 1.',
            'plan' => 'Order CBC, TFT. Start paracetamol 500mg PRN. Lifestyle advice: reduce screen time, hydration. Follow up in 1 week.',
            'vitals' => ['blood_pressure' => '130/85', 'heart_rate' => 78, 'temperature' => 36.8, 'weight' => 68, 'height' => 165, 'bmi' => 24.2],
        ]);
        MedicalRecord::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient2->id,
            'appointment_id' => $asAppt2->id,
            'user_id' => $admin7->id,
            'record_number' => 'MR-GEN-002',
            'subjective' => 'Annual checkup. Patient feels well overall. No complaints. Exercises regularly. Non-smoker.',
            'objective' => 'BP: 118/72 mmHg, HR: 65 bpm, Temp: 36.5°C, Weight: 82 kg, BMI: 25.8. Normal cardiac and respiratory exam.',
            'assessment' => 'Generally healthy. Slightly elevated BMI. No acute concerns.',
            'plan' => 'Order comprehensive metabolic panel and lipid profile. Maintain current exercise regimen. Dietary counseling for weight management.',
            'vitals' => ['blood_pressure' => '118/72', 'heart_rate' => 65, 'temperature' => 36.5, 'weight' => 82, 'height' => 178, 'bmi' => 25.8],
        ]);
        $asRx = Prescription::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient1->id,
            'medical_record_id' => $asRecord1->id,
            'appointment_id' => $asAppt1->id,
            'prescription_number' => 'RX-GEN-001',
            'notes' => 'Take with food. Return if symptoms persist.',
            'status' => 'dispensed',
        ]);
        PrescriptionItem::create([
            'business_id' => $biz7->id,
            'prescription_id' => $asRx->id,
            'medication_name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Every 8 hours as needed',
            'duration' => '7 days',
            'quantity' => 21,
            'instructions' => 'Take with food. Max 3g/day.',
        ]);
        PrescriptionItem::create([
            'business_id' => $biz7->id,
            'prescription_id' => $asRx->id,
            'medication_name' => 'Ferrous Sulfate',
            'dosage' => '325mg',
            'frequency' => 'Once daily',
            'duration' => '30 days',
            'quantity' => 30,
            'instructions' => 'Take on empty stomach for best absorption. May cause dark stools.',
        ]);
        InsuranceClaim::create([
            'business_id' => $biz7->id,
            'customer_id' => $asPatient2->id,
            'invoice_id' => null,
            'claim_number' => 'IC-GEN-001',
            'insurance_provider' => 'Misr Insurance',
            'policy_number' => 'MI-2024-88761',
            'claim_amount' => 300.00,
            'approved_amount' => 240.00,
            'status' => 'approved',
            'submitted_at' => now()->subDays(2),
            'resolved_at' => now()->subDay(),
            'rejection_reason' => null,
        ]);
        // -----------------------------------------------------------------------------------------------------------------
        // 8. AL-KARIM JEWELRY (Jewelry Store)
        // -----------------------------------------------------------------------------------------------------------------
        $biz8 = $this->resolveBusiness($jewelryType, 'Al-Karim Jewelry', 'al-karim-jewelry');
        $admin8 = $this->resolveAdmin($biz8, 'Karim Admin', 'admin@alkarim.com', 'karim_admin', $password);
        $jkChain = Product::create([
            'business_id' => $biz8->id,
            'created_by' => $admin8->id,
            'name' => '21K Gold Chain',
            'sku' => 'JWL-CHN-001',
            'barcode' => null,
            'price' => 48750.00,
            'cost' => 42000.00,
            'is_active' => true,
            'metadata' => ['carat' => 21, 'weight_grams' => 22.5, 'gem_type' => 'none', 'serial_number' => 'JW-9981', 'status' => 'available'],
        ]);
        $jkRing = Product::create([
            'business_id' => $biz8->id,
            'created_by' => $admin8->id,
            'name' => '24K Gold Ring',
            'sku' => 'JWL-RNG-002',
            'barcode' => null,
            'price' => 62000.00,
            'cost' => 54000.00,
            'is_active' => true,
            'metadata' => ['carat' => 24, 'weight_grams' => 18.2, 'gem_type' => 'Diamond', 'serial_number' => 'JW-9982', 'status' => 'available'],
        ]);
        $jkBracelet = Product::create([
            'business_id' => $biz8->id,
            'created_by' => $admin8->id,
            'name' => '18K Tennis Bracelet',
            'sku' => 'JWL-BRC-003',
            'barcode' => null,
            'price' => 85000.00,
            'cost' => 72000.00,
            'is_active' => true,
            'metadata' => ['carat' => 18, 'weight_grams' => 35.8, 'gem_type' => 'Diamond', 'serial_number' => 'JW-9983', 'status' => 'available'],
        ]);
        $jkNecklace = Product::create([
            'business_id' => $biz8->id,
            'created_by' => $admin8->id,
            'name' => '21K Arabic Necklace',
            'sku' => 'JWL-NKL-004',
            'barcode' => null,
            'price' => 95000.00,
            'cost' => 80000.00,
            'is_active' => true,
            'metadata' => ['carat' => 21, 'weight_grams' => 42.0, 'gem_type' => 'none', 'serial_number' => 'JW-9984', 'status' => 'sold'],
        ]);
        $jkCoin = Product::create([
            'business_id' => $biz8->id,
            'created_by' => $admin8->id,
            'name' => '24K Gold Coin 10g',
            'sku' => 'JWL-COIN-005',
            'barcode' => null,
            'price' => 18500.00,
            'cost' => 17800.00,
            'is_active' => true,
            'metadata' => ['carat' => 24, 'weight_grams' => 10.0, 'gem_type' => 'none', 'serial_number' => 'JW-9985', 'status' => 'available'],
        ]);
        GoldRateLog::create([
            'business_id' => $biz8->id,
            'carat' => 18,
            'rate_per_gram' => 1550.00,
            'source' => 'EGX Official',
            'notes' => 'Morning rate update',
        ]);
        GoldRateLog::create([
            'business_id' => $biz8->id,
            'carat' => 21,
            'rate_per_gram' => 1810.00,
            'source' => 'EGX Official',
            'notes' => 'Morning rate update',
        ]);
        GoldRateLog::create([
            'business_id' => $biz8->id,
            'carat' => 24,
            'rate_per_gram' => 2060.00,
            'source' => 'EGX Official',
            'notes' => 'Morning rate update',
        ]);
        $jkCust1 = Customer::create([
            'business_id' => $biz8->id,
            'type' => 'customer',
            'name' => 'Prince Abdullah',
            'phone' => '+201111112233',
            'email' => 'abdullah@email.com',
            'metadata' => ['preferred_carat' => 24, 'vip_status' => true, 'vip_since' => '2024-01-15'],
        ]);
        $jkCust2 = Customer::create([
            'business_id' => $biz8->id,
            'type' => 'customer',
            'name' => 'Lady Zainab',
            'phone' => '+201112223344',
            'email' => 'zainab@email.com',
            'metadata' => ['preferred_carat' => 21, 'vip_status' => true, 'vip_since' => '2025-06-01'],
        ]);
        $jkCust3 = Customer::create([
            'business_id' => $biz8->id,
            'type' => 'customer',
            'name' => 'Omar Al-Rashid',
            'phone' => '+201113334455',
            'email' => 'omar.rashid@email.com',
            'metadata' => ['preferred_carat' => 21, 'vip_status' => false],
        ]);
        RepairTicket::create([
            'business_id' => $biz8->id,
            'customer_id' => $jkCust2->id,
            'ticket_number' => 'RPR-001',
            'item_description' => '21K Gold Chain - broken clasp',
            'item_weight' => 15.8000,
            'item_carat' => 21,
            'issue_description' => 'Clasp broken at the lock mechanism. Chain itself is intact. Needs new lobster clasp soldered.',
            'estimated_cost' => 500.00,
            'actual_cost' => 450.00,
            'status' => 'in_progress',
            'received_at' => now()->subDays(2),
            'quoted_at' => now()->subDays(1),
            'completed_at' => null,
            'delivered_at' => null,
            'technician' => 'Master Ahmed',
        ]);
        PoliceBookEntry::create([
            'business_id' => $biz8->id,
            'entry_number' => 'PB-2026-001',
            'entry_type' => 'purchase',
            'item_description' => '21K Gold Chain - Fancy Link, 22.5g',
            'item_weight' => 22.5000,
            'item_carat' => 21,
            'metal_type' => 'Gold',
            'customer_name' => 'Prince Abdullah',
            'customer_id' => $jkCust1->id,
            'customer_id_number' => 'EG-1234567890',
            'transaction_date' => now()->subDays(10),
            'transaction_amount' => 42000.00,
            'notes' => 'Customer purchased gold chain. Full ID verified.',
        ]);
        PoliceBookEntry::create([
            'business_id' => $biz8->id,
            'entry_number' => 'PB-2026-002',
            'entry_type' => 'sale',
            'item_description' => '21K Arabic Necklace, 42g',
            'item_weight' => 42.0000,
            'item_carat' => 21,
            'metal_type' => 'Gold',
            'customer_name' => 'Lady Zainab',
            'customer_id' => $jkCust2->id,
            'customer_id_number' => 'EG-9876543210',
            'transaction_date' => now()->subDays(5),
            'transaction_amount' => 95000.00,
            'notes' => 'VIP customer purchase. Installment plan: 3 payments.',
        ]);
    }

    /**
     * Resolve or create a demo Business by slug. When the store was already
     * provisioned through the B2B onboarding pipeline (TenantSeeder), this
     * returns the existing row untouched (plan/city/contact/settings preserved).
     */
    private function resolveBusiness(BusinessType $type, string $name, string $slug): Business
    {
        return Business::firstOrCreate(
            ['slug' => $slug],
            [
                'id' => (string) Str::uuid(),
                'business_type_id' => $type->id,
                'name' => $name,
                'status' => 'active',
            ],
        );
    }

    /**
     * Resolve or create the store admin by (business, email). In the seeded
     * provisioning flow the row already exists as the central mirror created by
     * OnboardingService::activate(). Falling back to `create` keeps standalone
     * seeds (tests) self-sufficient.
     */
    private function resolveAdmin(Business $business, string $name, string $email, string $username, string $password): User
    {
        $admin = User::withoutBusiness()
            ->where('business_id', $business->id)
            ->where('email', $email)
            ->first();

        if ($admin) {
            return $admin;
        }

        return User::withoutBusiness()->create([
            'business_id' => $business->id,
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'role' => 'admin',
        ]);
    }
}
