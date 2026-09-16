<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
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
            'allowed_modules' => ['inventory', 'pos'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->businessType->id,
            'name' => 'Settings Test Retail',
            'slug' => 'settings-test-retail',
            'status' => 'active',
        ]);

        $this->user = $this->makeUser('admin');

        Sanctum::actingAs($this->user);
    }

    private function makeUser(string $role, string $suffix = ''): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => ucfirst($role).' '.$suffix,
            'username' => $role.'-'.Str::random(6),
            'email' => $role.'-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Settings Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 5,
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ], $overrides));
    }

    public function test_get_settings_returns_defaults_and_next_numbers(): void
    {
        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.sales_invoice_prefix', 'INV-')
            ->assertJsonPath('settings.purchase_order_prefix', 'PO-')
            ->assertJsonPath('settings.grn_prefix', 'GRN-')
            ->assertJsonPath('settings.allow_split_payments', false)
            ->assertJsonPath('settings.allow_credit_sales', false)
            ->assertJsonPath('settings.invoice_footer_terms', null)
            ->assertJsonPath('next_numbers.sales_invoice', 'INV-1')
            ->assertJsonPath('next_numbers.purchase_order', 'PO-1')
            ->assertJsonPath('next_numbers.grn', 'GRN-1');
    }

    public function test_update_settings_persists_business_name_and_admin_profile(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'business_name' => 'Renamed Retail',
            'admin_name' => 'New Admin',
            'admin_email' => 'new-admin@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('business_name', 'Renamed Retail')
            ->assertJsonPath('user.name', 'New Admin')
            ->assertJsonPath('user.email', 'new-admin@example.com');

        $this->assertDatabaseHas('businesses', ['id' => $this->business->id, 'name' => 'Renamed Retail']);
        $this->assertDatabaseHas('users', ['id' => $this->user->id, 'name' => 'New Admin', 'email' => 'new-admin@example.com']);
    }

    public function test_update_settings_persists_prefixes_terms_and_toggles(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'allow_split_payments' => true,
            'allow_credit_sales' => true,
            'sales_invoice_prefix' => 'SALE-',
            'purchase_order_prefix' => 'BUY-',
            'grn_prefix' => 'GRN-',
            'invoice_footer_terms' => 'Returns accepted within 7 days. Payment due in 30 days.',
        ])
            ->assertOk()
            ->assertJsonPath('settings.sales_invoice_prefix', 'SALE-')
            ->assertJsonPath('settings.purchase_order_prefix', 'BUY-')
            ->assertJsonPath('settings.grn_prefix', 'GRN-')
            ->assertJsonPath('settings.allow_split_payments', true)
            ->assertJsonPath('settings.allow_credit_sales', true)
            ->assertJsonPath('settings.invoice_footer_terms', 'Returns accepted within 7 days. Payment due in 30 days.');

        $settings = $this->business->fresh()->settings;
        $this->assertSame('SALE-', $settings['sales_invoice_prefix']);
        $this->assertSame('Returns accepted within 7 days. Payment due in 30 days.', $settings['invoice_footer_terms']);

        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.sales_invoice_prefix', 'SALE-')
            ->assertJsonPath('settings.invoice_footer_terms', 'Returns accepted within 7 days. Payment due in 30 days.');
    }

    public function test_update_settings_rejects_invalid_admin_email(): void
    {
        $this->putJson('/api/v1/businesses/settings', ['admin_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_email');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id, 'email' => 'not-an-email']);
    }

    public function test_update_settings_rejects_email_used_by_another_user(): void
    {
        $other = $this->makeUser('staff', 'other');

        $this->putJson('/api/v1/businesses/settings', ['admin_email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_email');
    }

    public function test_blank_prefixes_and_identity_fields_are_rejected(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'business_name' => '   ',
            'sales_invoice_prefix' => '',
            'purchase_order_prefix' => '   ',
            'grn_prefix' => '',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'sales_invoice_prefix', 'purchase_order_prefix', 'grn_prefix']);

        $this->assertDatabaseHas('businesses', [
            'id' => $this->business->id,
            'name' => 'Settings Test Retail',
        ]);
        $this->assertSame('INV-', $this->business->fresh()->mergedSettings()['sales_invoice_prefix']);
    }

    public function test_jofotara_secret_is_write_only_and_survives_unrelated_updates(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'jofotara_enabled' => true,
            'jofotara_client_id' => 'client-1',
            'jofotara_secret_key' => 'SECRET_123',
        ])
            ->assertOk()
            ->assertJsonPath('settings.jofotara_enabled', true)
            ->assertJsonPath('settings.jofotara_client_id', 'client-1')
            ->assertJsonPath('settings.jofotara_secret_key', null);

        $this->assertSame('SECRET_123', $this->business->fresh()->settings['jofotara_secret_key']);

        // The secret is never echoed by the read endpoints (settings or bootstrap).
        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.jofotara_secret_key', null);

        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('settings.jofotara_secret_key', null);

        // Editing unrelated settings keeps the stored secret intact.
        $this->putJson('/api/v1/businesses/settings', ['business_name' => 'Renamed Retail'])
            ->assertOk()
            ->assertJsonPath('business_name', 'Renamed Retail')
            ->assertJsonPath('settings.jofotara_secret_key', null);

        $this->assertSame('SECRET_123', $this->business->fresh()->settings['jofotara_secret_key']);

        // A fresh non-blank secret replaces the stored one.
        $this->putJson('/api/v1/businesses/settings', ['jofotara_secret_key' => 'SECRET_456'])
            ->assertOk()
            ->assertJsonPath('settings.jofotara_secret_key', null);

        $this->assertSame('SECRET_456', $this->business->fresh()->settings['jofotara_secret_key']);
    }

    public function test_invoice_numbers_are_sequential_per_business(): void
    {
        $first = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item A', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5]],
        ]);
        $first->assertStatus(201);
        $this->assertSame('INV-1', $first->json('invoice_number'));

        $second = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item B', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 0]],
        ]);
        $second->assertStatus(201);
        $this->assertSame('INV-2', $second->json('invoice_number'));

        $this->assertDatabaseHas('invoices', ['business_id' => $this->business->id, 'invoice_number' => 'INV-1']);
        $this->assertDatabaseHas('invoices', ['business_id' => $this->business->id, 'invoice_number' => 'INV-2']);
    }

    public function test_duplicate_invoice_gets_next_unique_number(): void
    {
        $original = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item A', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 5]],
        ]);
        $original->assertStatus(201);
        $this->assertSame('INV-1', $original->json('invoice_number'));

        $dupe = $this->postJson("/api/v1/invoices/{$original->json('id')}/duplicate");
        $dupe->assertStatus(201);
        $this->assertSame('INV-2', $dupe->json('invoice_number'));
        $this->assertNotSame($original->json('invoice_number'), $dupe->json('invoice_number'));
        $this->assertCount(1, $dupe->json('items'));

        $this->assertDatabaseHas('invoices', ['business_id' => $this->business->id, 'invoice_number' => 'INV-1']);
        $this->assertDatabaseHas('invoices', ['business_id' => $this->business->id, 'invoice_number' => 'INV-2']);
    }

    public function test_invoice_numbers_use_custom_prefix(): void
    {
        $this->putJson('/api/v1/businesses/settings', ['sales_invoice_prefix' => 'SALE-'])->assertOk();

        $invoice = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item A', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ]);
        $invoice->assertStatus(201);
        $this->assertSame('SALE-1', $invoice->json('invoice_number'));

        // A fresh GET reflects the next number for the custom prefix.
        $this->getJson('/api/v1/businesses/settings')
            ->assertJsonPath('next_numbers.sales_invoice', 'SALE-2');
    }

    public function test_purchase_order_numbers_are_sequential(): void
    {
        $product = $this->makeProduct();

        $first = $this->postJson('/api/v1/purchase-orders', [
            'items' => [['product_id' => $product->id, 'name' => 'Item A', 'quantity' => 5, 'unit_cost' => 3]],
        ]);
        $first->assertStatus(201);
        $this->assertSame('PO-1', $first->json('order_number'));

        $second = $this->postJson('/api/v1/purchase-orders', [
            'items' => [['product_id' => $product->id, 'name' => 'Item B', 'quantity' => 2, 'unit_cost' => 4]],
        ]);
        $second->assertStatus(201);
        $this->assertSame('PO-2', $second->json('order_number'));
    }

    public function test_goods_receipt_numbers_are_sequential(): void
    {
        $supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Settings Supplier',
            'phone' => '+962791111111',
        ]);
        $product = $this->makeProduct();

        $po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'name' => 'Item A', 'quantity' => 5, 'unit_cost' => 3]],
        ]);
        $po->assertStatus(201);
        $poId = $po->json('id');
        $poItemId = $po->json('items.0.id');

        $this->putJson("/api/v1/purchase-orders/{$poId}", ['status' => 'ordered'])->assertOk();

        $first = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $poId,
            'items' => [['purchase_order_item_id' => $poItemId, 'product_id' => $product->id, 'received_quantity' => 5]],
        ]);
        $first->assertStatus(201);
        $this->assertSame('GRN-1', $first->json('receipt_number'));

        $this->assertDatabaseHas('goods_receipts', ['business_id' => $this->business->id, 'receipt_number' => 'GRN-1']);
    }

    public function test_cashier_cannot_access_settings(): void
    {
        $cashier = $this->makeUser('cashier');
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/businesses/settings')->assertStatus(403);
        $this->putJson('/api/v1/businesses/settings', ['business_name' => 'Hack'])->assertStatus(403);
    }

    public function test_logo_persists_and_round_trips(): void
    {
        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('logo', null);

        $dataUrl = 'data:image/png;base64,'.base64_encode('fake-png-bytes');

        $this->putJson('/api/v1/businesses/settings', ['logo' => $dataUrl])
            ->assertOk()
            ->assertJsonPath('logo', $dataUrl);

        $this->getJson('/api/v1/businesses/settings')
            ->assertJsonPath('logo', $dataUrl);

        $this->assertDatabaseHas('businesses', ['id' => $this->business->id, 'logo' => $dataUrl]);
    }

    public function test_profile_password_change_requires_current_password(): void
    {
        $this->postJson('/api/v1/profile/password', [
            'current_password' => 'wrong-password',
            'new_password' => 'Str0ng!Pass',
            'new_password_confirmation' => 'Str0ng!Pass',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_profile_password_change_updates_password_and_rotates_token(): void
    {
        $this->postJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'new_password' => 'Str0ng!Pass',
            'new_password_confirmation' => 'Str0ng!Pass',
        ])->assertOk()
            ->assertJsonPath('message', 'Password changed successfully. Please sign in again.');

        $this->assertTrue(Hash::check('Str0ng!Pass', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());

        // Weak passwords rejected before any change.
        $user = $this->makeUser('admin', 'second');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'new_password' => 'weak',
            'new_password_confirmation' => 'weak',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('new_password');
    }

    public function test_profile_update_persists_avatar_and_personal_details(): void
    {
        $avatar = 'data:image/png;base64,'.base64_encode('fake-avatar-bytes');

        $this->postJson('/api/v1/profile', [
            'name' => 'Updated Person',
            'email' => 'updated-person@example.com',
            'avatar' => $avatar,
        ])->assertOk()
            ->assertJsonPath('user.name', 'Updated Person')
            ->assertJsonPath('user.email', 'updated-person@example.com')
            ->assertJsonPath('user.avatar', $avatar);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Updated Person',
            'email' => 'updated-person@example.com',
            'avatar' => $avatar,
        ]);
    }

    public function test_profile_update_rejects_email_used_by_another_user(): void
    {
        $other = $this->makeUser('staff', 'other');

        $this->postJson('/api/v1/profile', ['email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id, 'email' => $other->email]);
    }

    public function test_profile_avatar_can_be_cleared(): void
    {
        $this->postJson('/api/v1/profile', ['avatar' => 'data:image/png;base64,abc'])
            ->assertOk()
            ->assertJsonPath('user.avatar', 'data:image/png;base64,abc');

        $this->postJson('/api/v1/profile', ['avatar' => null])
            ->assertOk()
            ->assertJsonPath('user.avatar', null);

        $this->assertDatabaseHas('users', ['id' => $this->user->id, 'avatar' => null]);
    }

    public function test_avatar_is_included_in_owner_login_response(): void
    {
        $owner = User::withoutBusiness()->create([
            'business_id' => null,
            'name' => 'Owner Avatar',
            'username' => 'owner-avatar',
            'email' => 'owner-avatar@superx.test',
            'password' => Hash::make('OwnerPass1@'),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        $avatar = 'data:image/png;base64,'.base64_encode('fake-avatar-bytes');
        $owner->update(['avatar' => $avatar]);

        $this->postJson('/api/v1/login', [
            'username' => 'owner-avatar',
            'password' => 'OwnerPass1@',
        ])->assertOk()
            ->assertJsonPath('user.avatar', $avatar)
            ->assertJsonPath('user.name', 'Owner Avatar');
    }

    public function test_document_sequence_counter_persists_and_peek_does_not_advance(): void
    {
        $first = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item A', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ]);
        $first->assertStatus(201);
        $this->assertSame('INV-1', $first->json('invoice_number'));

        $this->assertDatabaseHas('document_sequences', [
            'business_id' => $this->business->id,
            'kind' => 'invoice',
            'prefix' => 'INV-',
            'current' => 1,
        ]);

        // peek() (settings next_numbers) is read-only and never advances the counter.
        $this->getJson('/api/v1/businesses/settings')
            ->assertJsonPath('next_numbers.sales_invoice', 'INV-2');
        $this->assertDatabaseHas('document_sequences', [
            'business_id' => $this->business->id,
            'kind' => 'invoice',
            'prefix' => 'INV-',
            'current' => 1,
        ]);

        $second = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item B', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 0]],
        ]);
        $second->assertStatus(201);
        $this->assertSame('INV-2', $second->json('invoice_number'));
    }

    public function test_rolled_back_allocation_does_not_burn_a_number(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 0]);

        // The number is allocated inside the store transaction, then the stock
        // guard throws -> the whole transaction (counter bump included) rolls back.
        $failed = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['product_id' => $product->id, 'name' => 'Item A', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ]);
        $failed->assertStatus(422);

        $this->assertDatabaseMissing('invoices', ['business_id' => $this->business->id, 'invoice_number' => 'INV-1']);

        // The same number is reused after the rollback - no gap in the sequence.
        $ok = $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Item B', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 0]],
        ]);
        $ok->assertStatus(201);
        $this->assertSame('INV-1', $ok->json('invoice_number'));
    }

    public function test_retry_on_conflict_reruns_the_callback_with_a_fresh_number(): void
    {
        $conflict = $this->uniqueViolation(
            'duplicate key value violates unique constraint "invoices_business_id_invoice_number_unique"'
        );

        $calls = 0;
        $result = DocumentNumberService::retryOnConflict(function () use (&$calls, $conflict) {
            $calls++;

            if ($calls === 1) {
                throw $conflict;
            }

            return 'INV-11';
        }, 'invoices');

        $this->assertSame('INV-11', $result);
        $this->assertSame(2, $calls);
    }

    public function test_retry_on_conflict_rethrows_when_attempts_are_exhausted(): void
    {
        $conflict = $this->uniqueViolation(
            'duplicate key value violates unique constraint "invoices_business_id_invoice_number_unique"'
        );

        $calls = 0;
        $this->expectException(QueryException::class);
        DocumentNumberService::retryOnConflict(function () use (&$calls, $conflict) {
            $calls++;
            throw $conflict;
        }, 'invoices');

        $this->assertSame(3, $calls);
    }

    public function test_retry_on_conflict_rethrows_non_conflict_exceptions_immediately(): void
    {
        $conflict = $this->uniqueViolation(
            'duplicate key value violates unique constraint "invoices_business_id_invoice_number_unique"'
        );

        $calls = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock');
        DocumentNumberService::retryOnConflict(function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('Insufficient stock');
        }, 'invoices');

        $this->assertSame(1, $calls);
    }

    public function test_get_settings_returns_retail_defaults(): void
    {
        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.tax_number', null)
            ->assertJsonPath('settings.phone', null)
            ->assertJsonPath('settings.address', null)
            ->assertJsonPath('settings.auto_print_receipt', false)
            ->assertJsonPath('settings.receipt_paper_width', '80mm')
            ->assertJsonPath('settings.receipt_footer_message', null)
            ->assertJsonPath('settings.allow_negative_stock', false)
            ->assertJsonPath('settings.scale_barcode_parsing', false)
            ->assertJsonPath('settings.scale_barcode_prefix', '20')
            ->assertJsonPath('settings.expiry_warning_days', 30);
    }

    public function test_update_settings_persists_retail_sections(): void
    {
        $this->putJson('/api/v1/businesses/settings', [
            'tax_number' => 'JO123456789',
            'phone' => '+962785555555',
            'address' => 'King Hussein St, Amman',
            'auto_print_receipt' => true,
            'receipt_paper_width' => '58mm',
            'receipt_footer_message' => 'Thanks for shopping with us!',
            'allow_negative_stock' => true,
            'scale_barcode_parsing' => true,
            'scale_barcode_prefix' => '22',
            'expiry_warning_days' => 45,
        ])
            ->assertOk()
            ->assertJsonPath('settings.tax_number', 'JO123456789')
            ->assertJsonPath('settings.phone', '+962785555555')
            ->assertJsonPath('settings.address', 'King Hussein St, Amman')
            ->assertJsonPath('settings.auto_print_receipt', true)
            ->assertJsonPath('settings.receipt_paper_width', '58mm')
            ->assertJsonPath('settings.receipt_footer_message', 'Thanks for shopping with us!')
            ->assertJsonPath('settings.allow_negative_stock', true)
            ->assertJsonPath('settings.scale_barcode_parsing', true)
            ->assertJsonPath('settings.scale_barcode_prefix', '22')
            ->assertJsonPath('settings.expiry_warning_days', 45);

        $this->putJson('/api/v1/businesses/settings', ['receipt_paper_width' => 'wide'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt_paper_width');

        $this->putJson('/api/v1/businesses/settings', ['scale_barcode_prefix' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scale_barcode_prefix');

        $this->putJson('/api/v1/businesses/settings', ['expiry_warning_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_warning_days');

        $settings = $this->business->fresh()->settings;
        $this->assertSame('58mm', $settings['receipt_paper_width']);
        $this->assertSame('Thanks for shopping with us!', $settings['receipt_footer_message']);
        $this->assertTrue($settings['allow_negative_stock']);

        $this->getJson('/api/v1/businesses/settings')
            ->assertOk()
            ->assertJsonPath('settings.tax_number', 'JO123456789')
            ->assertJsonPath('settings.scale_barcode_prefix', '22')
            ->assertJsonPath('settings.expiry_warning_days', 45);
    }

    public function test_invoice_store_rejects_out_of_stock_simple_product_by_default(): void
    {
        $product = $this->makeProduct(['name' => 'OOS Item', 'stock_quantity' => 0]);

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'OOS Item', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 0],
            ],
        ])
            ->assertStatus(422);

        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
        $this->assertDatabaseMissing('invoices', ['business_id' => $this->business->id]);
    }

    public function test_allow_negative_stock_permits_overselling_simple_products(): void
    {
        $product = $this->makeProduct(['name' => 'OOS Item', 'stock_quantity' => 0]);

        $this->putJson('/api/v1/businesses/settings', ['allow_negative_stock' => true])->assertOk();

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'OOS Item', 'quantity' => 3, 'unit_price' => 10, 'tax_rate' => 0],
            ],
        ])
            ->assertStatus(201)
            ->assertJsonPath('payment_status', 'paid');

        $this->assertSame(-3.0, (float) $product->fresh()->stock_quantity);
    }

    public function test_allow_negative_stock_permits_batch_oversell_against_last_batch(): void
    {
        $product = $this->makeProduct(['name' => 'Batch OOS', 'has_batch' => true, 'stock_quantity' => 0]);
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => 'BN-'.Str::random(6),
            'quantity' => 1,
            'quantity_sold' => 0,
            'cost_per_unit' => 2,
            'total_cost' => 2,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->putJson('/api/v1/businesses/settings', ['allow_negative_stock' => true])->assertOk();

        $this->postJson('/api/v1/invoices', [
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [
                ['product_id' => $product->id, 'name' => 'Batch OOS', 'quantity' => 3, 'unit_price' => 10, 'tax_rate' => 0],
            ],
        ])
            ->assertStatus(201);

        $this->assertSame(3.0, (float) $batch->fresh()->quantity_sold);
        $this->assertSame(-2.0, (float) $product->fresh()->stock_quantity);
    }

    public function test_scale_barcode_lookup_parses_weight_when_enabled(): void
    {
        $eanProduct = $this->makeProduct(['name' => 'EAN Item', 'barcode' => '4006381333931']);
        $scaleProduct = $this->makeProduct(['name' => 'Scale Item', 'barcode' => '123456']);

        $this->postJson('/api/v1/products/barcode/lookup', ['barcode' => '4006381333931'])
            ->assertOk()
            ->assertJsonPath('id', $eanProduct->id);

        $this->postJson('/api/v1/products/barcode/lookup', ['barcode' => '2012345601500'])
            ->assertStatus(404);

        $this->putJson('/api/v1/businesses/settings', ['scale_barcode_parsing' => true])->assertOk();

        $this->postJson('/api/v1/products/barcode/lookup', ['barcode' => '2012345601500'])
            ->assertOk()
            ->assertJsonPath('id', $scaleProduct->id)
            ->assertJsonPath('scale_weight', 1.5);
    }

    private function uniqueViolation(string $message): QueryException
    {
        $pdo = new \PDOException($message, '23505');
        $pdo->errorInfo = ['23505', '0', $message];

        return new QueryException('pgsql', 'insert into invoices ...', [], $pdo);
    }
}
