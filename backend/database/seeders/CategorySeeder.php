<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
  public function run(): void
  {
        $supermarket = BusinessType::where('slug', 'supermarket_hypermarket')->value('id');
    $clothing    = BusinessType::where('slug', 'clothing_apparel')->value('id');
    $electronics  = BusinessType::where('slug', 'electronics_warranty')->value('id');
    $fastFood    = BusinessType::where('slug', 'fast_food_kds')->value('id');
    $fineDining  = BusinessType::where('slug', 'fine_dining_reservations')->value('id');
    $jewelry     = BusinessType::where('slug', 'jewelry_store')->value('id');

    $categories = [
      ['business_type_id' => $supermarket, 'sort_order' => 1,  'name' => 'Fruits & Veg',    'name_ar' => 'خضار وفواكه', 'color' => '#22C55E'],
      ['business_type_id' => $supermarket, 'sort_order' => 2,  'name' => 'Dairy',            'name_ar' => 'ألبان',       'color' => '#3B82F6'],
      ['business_type_id' => $supermarket, 'sort_order' => 3,  'name' => 'Frozen',           'name_ar' => 'مجمدات',      'color' => '#06B6D4'],
      ['business_type_id' => $supermarket, 'sort_order' => 4,  'name' => 'Beverages',        'name_ar' => 'مشروبات',     'color' => '#F59E0B'],
      ['business_type_id' => $supermarket, 'sort_order' => 5,  'name' => 'Snacks',           'name_ar' => 'وجبات خفيفة', 'color' => '#A855F7'],
      ['business_type_id' => $supermarket, 'sort_order' => 6,  'name' => 'Bakery',           'name_ar' => 'مخبوزات',     'color' => '#D97706'],
      ['business_type_id' => $supermarket, 'sort_order' => 7,  'name' => 'Meat',             'name_ar' => 'لحوم',        'color' => '#EF4444'],
      ['business_type_id' => $supermarket, 'sort_order' => 8,  'name' => 'Other',            'name_ar' => 'أخرى',        'color' => '#64748B'],

      ['business_type_id' => $clothing, 'sort_order' => 1,  'name' => 'Tops',              'name_ar' => 'قمصان'],
      ['business_type_id' => $clothing, 'sort_order' => 2,  'name' => 'Bottoms',           'name_ar' => 'بناطيل'],
      ['business_type_id' => $clothing, 'sort_order' => 3,  'name' => 'Dresses',           'name_ar' => 'فساتين'],
      ['business_type_id' => $clothing, 'sort_order' => 4,  'name' => 'Outerwear',         'name_ar' => 'معاطف'],
      ['business_type_id' => $clothing, 'sort_order' => 5,  'name' => 'Accessories',       'name_ar' => 'إكسسوارات'],
      ['business_type_id' => $clothing, 'sort_order' => 6,  'name' => 'Footwear',          'name_ar' => 'أحذية'],

      ['business_type_id' => $electronics, 'sort_order' => 1, 'name' => 'Phones',           'name_ar' => 'هواتف'],
      ['business_type_id' => $electronics, 'sort_order' => 2, 'name' => 'Laptops',          'name_ar' => 'لابتوب'],
      ['business_type_id' => $electronics, 'sort_order' => 3, 'name' => 'Accessories',      'name_ar' => 'إكسسوارات'],
      ['business_type_id' => $electronics, 'sort_order' => 4, 'name' => 'Audio',            'name_ar' => 'صوتيات'],
      ['business_type_id' => $electronics, 'sort_order' => 5, 'name' => 'Gaming',           'name_ar' => 'ألعاب'],

      ['business_type_id' => $fastFood, 'sort_order' => 1, 'name' => 'Burgers',           'name_ar' => 'برغر'],
      ['business_type_id' => $fastFood, 'sort_order' => 2, 'name' => 'Beverages',          'name_ar' => 'مشروبات'],
      ['business_type_id' => $fastFood, 'sort_order' => 3, 'name' => 'Sides',             'name_ar' => 'إضافات'],
      ['business_type_id' => $fastFood, 'sort_order' => 4, 'name' => 'Desserts',          'name_ar' => 'حلويات'],

      ['business_type_id' => $fineDining, 'sort_order' => 1, 'name' => 'Appetizers',       'name_ar' => 'مقبلات'],
      ['business_type_id' => $fineDining, 'sort_order' => 2, 'name' => 'Mains',            'name_ar' => 'أطباق رئيسية'],
      ['business_type_id' => $fineDining, 'sort_order' => 3, 'name' => 'Desserts',         'name_ar' => 'حلويات'],
      ['business_type_id' => $fineDining, 'sort_order' => 4, 'name' => 'Beverages',        'name_ar' => 'مشروبات'],
      ['business_type_id' => $fineDining, 'sort_order' => 5, 'name' => 'Wine',             'name_ar' => 'نبيذ'],

      ['business_type_id' => $jewelry, 'sort_order' => 1, 'name' => 'Rings',             'name_ar' => 'خواتم'],
      ['business_type_id' => $jewelry, 'sort_order' => 2, 'name' => 'Necklaces',         'name_ar' => 'قلائد'],
      ['business_type_id' => $jewelry, 'sort_order' => 3, 'name' => 'Bracelets',         'name_ar' => 'أساور'],
      ['business_type_id' => $jewelry, 'sort_order' => 4, 'name' => 'Earrings',          'name_ar' => 'أقراط'],
      ['business_type_id' => $jewelry, 'sort_order' => 5, 'name' => 'Watches',           'name_ar' => 'ساعات'],
    ];

    foreach ($categories as $cat) {
      Category::updateOrCreate(
        ['business_type_id' => $cat['business_type_id'], 'name' => $cat['name']],
        $cat
      );
    }

    // Supermarket sub-categories (parent → child) for finer food filtering.
    $subCategories = [
      ['parent' => 'Fruits & Veg', 'name' => 'Fruits',    'name_ar' => 'فواكه',    'color' => '#16A34A', 'sort_order' => 1],
      ['parent' => 'Fruits & Veg', 'name' => 'Vegetables','name_ar' => 'خضروات',   'color' => '#65A30D', 'sort_order' => 2],
      ['parent' => 'Dairy',        'name' => 'Milk',      'name_ar' => 'حليب',      'color' => '#60A5FA', 'sort_order' => 1],
      ['parent' => 'Dairy',        'name' => 'Cheese',    'name_ar' => 'أجبان',     'color' => '#818CF8', 'sort_order' => 2],
      ['parent' => 'Beverages',    'name' => 'Water',     'name_ar' => 'مياه',      'color' => '#38BDF8', 'sort_order' => 1],
      ['parent' => 'Beverages',    'name' => 'Juices',    'name_ar' => 'عصائر',     'color' => '#FB923C', 'sort_order' => 2],
    ];

    foreach ($subCategories as $sub) {
      $parent = Category::where('business_type_id', $supermarket)
        ->where('name', $sub['parent'])
        ->first();

      if ($parent) {
        Category::updateOrCreate(
          ['business_type_id' => $supermarket, 'name' => $sub['name']],
          [
            'business_type_id' => $supermarket,
            'parent_id' => $parent->id,
            'name' => $sub['name'],
            'name_ar' => $sub['name_ar'],
            'color' => $sub['color'],
            'sort_order' => $sub['sort_order'],
          ]
        );
      }
    }
  }
}
