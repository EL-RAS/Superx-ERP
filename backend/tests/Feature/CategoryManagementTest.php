<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private BusinessType $businessType;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['sales', 'pos', 'purchases', 'inventory', 'accounting', 'users', 'crm', 'reports'],
            'default_settings' => [],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Fresh Mart',
            'slug' => 'fresh-mart',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'username' => 'admin-'.Str::random(6),
            'email' => 'admin-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_primary_admin' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    private function makeCategory(string $name, ?int $parentId = null, ?string $color = null): Category
    {
        return Category::create([
            'business_type_id' => $this->businessType->id,
            'parent_id' => $parentId,
            'name' => $name,
            'color' => $color,
        ]);
    }

    private function makeProduct(string $name, ?int $categoryId = null): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => $name,
            'price' => 10,
            'cost' => 5,
            'unit' => 'pcs',
            'category_id' => $categoryId,
            'category' => $categoryId ? Category::find($categoryId)?->name : null,
        ]);
    }

    public function test_index_lists_categories_with_linked_product_count(): void
    {
        $cat = $this->makeCategory('Dairy', null, '#3B82F6');
        $this->makeProduct('Milk', $cat->id);
        $this->makeProduct('Cheese', $cat->id);
        $this->makeCategory('Bakery');

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $cat->id, 'name' => 'Dairy', 'color' => '#3B82F6', 'linked_products_count' => 2])
            ->assertJsonFragment(['name' => 'Bakery', 'linked_products_count' => 0]);
    }

    public function test_show_returns_single_category_with_count(): void
    {
        $cat = $this->makeCategory('Frozen');
        $this->makeProduct('Ice Cream', $cat->id);

        $this->getJson("/api/v1/categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('id', $cat->id)
            ->assertJsonPath('linked_products_count', 1);
    }

    public function test_store_creates_category_with_color_and_name_ar(): void
    {
        $this->postJson('/api/v1/categories', [
            'name' => 'Produce',
            'name_ar' => 'خضار',
            'color' => '#22C55E',
        ])->assertStatus(201)
            ->assertJsonPath('name', 'Produce')
            ->assertJsonPath('name_ar', 'خضار')
            ->assertJsonPath('color', '#22C55E')
            ->assertJsonPath('linked_products_count', 0);

        $this->assertDatabaseHas('categories', ['name' => 'Produce', 'color' => '#22C55E']);
    }

    public function test_store_rejects_parent_from_another_business_type(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'clothing_apparel',
            'name_en' => 'Clothing',
            'name_ar' => 'ألبسة',
            'allowed_modules' => [],
            'default_settings' => [],
        ]);
        $foreignParent = Category::create([
            'business_type_id' => $otherType->id,
            'name' => 'Foreign',
        ]);

        $this->postJson('/api/v1/categories', [
            'name' => 'Candy',
            'parent_id' => $foreignParent->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_update_renames_category_and_syncs_product_category_string(): void
    {
        $cat = $this->makeCategory('Dairy');
        $this->makeProduct('Milk', $cat->id);

        $this->putJson("/api/v1/categories/{$cat->id}", [
            'name' => 'Dairy & Eggs',
            'color' => '#EF4444',
        ])->assertOk()
            ->assertJsonPath('name', 'Dairy & Eggs')
            ->assertJsonPath('color', '#EF4444');

        $this->assertSame('Dairy & Eggs', $this->business->products()->firstWhere('category_id', $cat->id)->category);
    }

    public function test_update_rejects_self_parent(): void
    {
        $cat = $this->makeCategory('Dairy');

        $this->putJson("/api/v1/categories/{$cat->id}", [
            'parent_id' => $cat->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_delete_unlinked_category_hard_deletes(): void
    {
        $cat = $this->makeCategory('Snacks');

        $this->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted.');

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_delete_reassigns_products_to_target_category(): void
    {
        $source = $this->makeCategory('Snacks');
        $target = $this->makeCategory('Party Foods');
        $this->makeProduct('Chips', $source->id);
        $this->makeProduct('Popcorn', $source->id);

        $this->deleteJson("/api/v1/categories/{$source->id}", [
            'reassign_to' => $target->id,
        ])->assertOk()
            ->assertJsonPath('message', 'Category deleted.');

        $this->assertDatabaseMissing('categories', ['id' => $source->id]);
        $this->assertSame(0, $source->products()->count());
        $this->assertSame(2, $this->business->products()->where('category_id', $target->id)->count());
        $this->assertSame('Party Foods', $this->business->products()->where('category_id', $target->id)->value('category'));
    }

    public function test_delete_without_target_moves_products_to_uncategorized(): void
    {
        $source = $this->makeCategory('Snacks');
        $this->makeProduct('Chips', $source->id);

        $this->deleteJson("/api/v1/categories/{$source->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $source->id]);
        $product = $this->business->products()->firstWhere('name', 'Chips');
        $this->assertNull($product->category_id);
        $this->assertNull($product->category);
    }

    public function test_delete_floating_category_detaches_children(): void
    {
        $parent = $this->makeCategory('Produce');
        $child = $this->makeCategory('Fruits', $parent->id);

        $this->deleteJson("/api/v1/categories/{$parent->id}")->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_delete_rejects_reassigning_to_self(): void
    {
        $cat = $this->makeCategory('Snacks');

        $this->deleteJson("/api/v1/categories/{$cat->id}", ['reassign_to' => $cat->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reassign_to');

        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
    }

    public function test_category_routes_are_scoped_to_business_type(): void
    {
        $otherType = BusinessType::create([
            'slug' => 'pharmacy',
            'name_en' => 'Pharmacy',
            'name_ar' => 'صيدلية',
            'allowed_modules' => [],
            'default_settings' => [],
        ]);
        $foreign = Category::create([
            'business_type_id' => $otherType->id,
            'name' => 'Foreign Category',
        ]);

        $this->getJson("/api/v1/categories/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/categories/{$foreign->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/categories/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $foreign->id]);
    }

    public function test_category_delete_requires_inventory_delete_permission(): void
    {
        $cat = $this->makeCategory('Snacks');

        $cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'Cashier',
            'username' => 'cash-'.Str::random(6),
            'email' => 'cash-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($cashier);

        $this->deleteJson("/api/v1/categories/{$cat->id}")->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
    }

    public function test_store_creates_subcategory_with_parent_and_explicit_sort_order(): void
    {
        $parent = $this->makeCategory('Produce');

        $this->postJson('/api/v1/categories', [
            'name' => 'Fruits',
            'name_ar' => 'فواكه',
            'parent_id' => $parent->id,
            'sort_order' => 3,
        ])->assertStatus(201)
            ->assertJsonPath('parent_id', $parent->id)
            ->assertJsonPath('name_ar', 'فواكه')
            ->assertJsonPath('sort_order', 3);

        $this->assertDatabaseHas('categories', ['name' => 'Fruits', 'parent_id' => $parent->id, 'sort_order' => 3]);
    }

    public function test_store_defaults_sort_order_to_max_plus_one(): void
    {
        $this->postJson('/api/v1/categories', ['name' => 'First', 'sort_order' => 5])->assertStatus(201);
        $this->assertDatabaseHas('categories', ['name' => 'First', 'sort_order' => 5]);

        $this->postJson('/api/v1/categories', ['name' => 'Second'])
            ->assertStatus(201)
            ->assertJsonPath('sort_order', 6);
    }

    public function test_update_changes_parent_name_ar_and_sort_order(): void
    {
        $parentA = $this->makeCategory('Drinks');
        $child = $this->makeCategory('Juices', $parentA->id);
        $parentB = $this->makeCategory('Frozen');

        $this->putJson("/api/v1/categories/{$child->id}", [
            'parent_id' => $parentB->id,
            'name_ar' => 'عصائر',
            'sort_order' => 9,
        ])->assertOk()
            ->assertJsonPath('parent_id', $parentB->id)
            ->assertJsonPath('name_ar', 'عصائر')
            ->assertJsonPath('sort_order', 9);

        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $parentB->id, 'sort_order' => 9]);
    }

    public function test_update_can_promote_subcategory_back_to_root(): void
    {
        $parent = $this->makeCategory('Produce');
        $child = $this->makeCategory('Fruits', $parent->id);

        $this->putJson("/api/v1/categories/{$child->id}", [
            'parent_id' => null,
        ])->assertOk()
            ->assertJsonPath('parent_id', null);

        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => null]);
    }

    public function test_update_rejects_moving_category_under_its_own_descendant(): void
    {
        $grand = $this->makeCategory('Produce');
        $parent = $this->makeCategory('Fruits', $grand->id);
        $child = $this->makeCategory('Apples', $parent->id);

        $this->putJson("/api/v1/categories/{$grand->id}", [
            'parent_id' => $child->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');

        $this->assertDatabaseHas('categories', ['id' => $grand->id, 'parent_id' => null]);
    }
}
