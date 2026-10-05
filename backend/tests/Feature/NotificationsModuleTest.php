<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsModuleTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $businessType = BusinessType::create([
            'slug' => 'supermarket',
            'name_en' => 'Supermarket',
            'name_ar' => 'Supermarket AR',
            'allowed_modules' => ['sales', 'pos', 'inventory', 'purchases'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Notification Retail',
            'slug' => 'notification-retail',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Notify Admin',
            'username' => 'notify-admin',
            'email' => 'notify-admin@example.com',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->user);
    }

    // ---------------------------------------------------------------- inbox

    public function test_notification_list_is_empty_for_a_new_user(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unread_count_reports_zero_so_the_badge_stays_hidden(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_list_returns_the_shape_the_header_dropdown_renders(): void
    {
        $this->seedNotification('demo');

        $payload = $this->getJson('/api/v1/notifications')->assertOk()->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame(1, $payload['unread_count']);

        $row = $payload['data'][0];

        foreach (['id', 'type', 'tag', 'severity', 'title', 'message', 'action_url', 'read', 'read_at', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $row, "Notification row is missing `{$key}`.");
        }

        $this->assertSame('demo', $row['tag']);
        $this->assertFalse($row['read']);
        $this->assertNull($row['read_at']);
    }

    // -------------------------------------------------------------- welcome

    public function test_welcome_alert_is_sent_once_and_stays_sent_once_read(): void
    {
        $service = app(NotificationService::class);

        $service->welcome($this->user);
        $service->welcome($this->user);

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertNotNull($this->notificationFor('welcome'));

        // Reading a welcome must never re-arm it — "first time" means first time.
        $this->notificationFor('welcome')->markAsRead();

        $service->welcome($this->user);

        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_welcome_is_skipped_for_a_user_without_a_business(): void
    {
        $owner = User::create([
            'business_id' => null,
            'name' => 'Platform Owner',
            'username' => 'notify-owner',
            'email' => 'notify-owner@example.com',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'admin',
        ]);

        app(NotificationService::class)->welcome($owner);

        $this->assertSame(0, $owner->notifications()->count());
    }

    // ----------------------------------------------------------------- stock

    public function test_stock_alerts_fire_on_the_reorder_threshold_and_rearm_after_read(): void
    {
        $product = $this->makeProduct(['min_stock' => 10, 'stock_quantity' => 25]);

        $lowTag = "stock_low:product:{$product->id}";
        $outTag = "stock_out:product:{$product->id}";

        // Still above the threshold — nothing to say.
        $product->update(['stock_quantity' => 20]);
        $this->assertNull($this->notificationFor($lowTag));
        $this->assertNull($this->notificationFor($outTag));

        // Crossing the threshold raises exactly one alert.
        $product->update(['stock_quantity' => 8]);
        $low = $this->notificationFor($lowTag);
        $this->assertNotNull($low);
        $this->assertSame('warning', $low->data['severity']);

        // Drifting further down must not spam the badge.
        $product->update(['stock_quantity' => 7]);
        $this->assertSame(1, $this->countFor($lowTag));

        // Running out is a distinct, more severe alert rather than a re-send.
        $product->update(['stock_quantity' => 0]);
        $out = $this->notificationFor($outTag);
        $this->assertNotNull($out);
        $this->assertSame('danger', $out->data['severity']);
        $this->assertSame(2, $this->user->notifications()->count());

        // Reading the low-stock alert re-arms it for the next real dip.
        $low->markAsRead();
        $product->update(['stock_quantity' => 9]);
        $this->assertSame(2, $this->countFor($lowTag));
    }

    public function test_products_without_a_reorder_threshold_never_alert(): void
    {
        $product = $this->makeProduct(['min_stock' => 0, 'stock_quantity' => 5]);

        $product->update(['stock_quantity' => 4]);
        $product->update(['stock_quantity' => 0]);

        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_every_active_user_of_the_business_receives_the_alert(): void
    {
        $second = User::create([
            'business_id' => $this->business->id,
            'name' => 'Second Cashier',
            'username' => 'notify-cashier',
            'email' => 'notify-cashier@example.com',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'cashier',
        ]);

        $this->makeProduct(['min_stock' => 10, 'stock_quantity' => 25])
            ->update(['stock_quantity' => 3]);

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_an_inactive_user_is_not_a_recipient(): void
    {
        $inactive = User::create([
            'business_id' => $this->business->id,
            'name' => 'Departed Clerk',
            'username' => 'notify-departed',
            'email' => 'notify-departed@example.com',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'staff',
            'is_active' => false,
        ]);

        $this->makeProduct(['min_stock' => 10, 'stock_quantity' => 25])
            ->update(['stock_quantity' => 3]);

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
    }

    // ---------------------------------------------------------------- expiry

    public function test_expiry_alerts_fire_for_expiring_and_expired_batches(): void
    {
        $product = $this->makeProduct(['has_batch' => true, 'min_stock' => 0, 'stock_quantity' => 0]);

        $soon = $this->makeBatch($product, 'EXP-SOON', now()->addDays(5));
        $gone = $this->makeBatch($product, 'EXP-GONE', now()->subDays(2));
        $fine = $this->makeBatch($product, 'EXP-FINE', now()->addDays(90));

        $this->assertNotNull($this->notificationFor("expiry_soon:batch:{$soon->id}"));
        $this->assertNotNull($this->notificationFor("expiry_passed:batch:{$gone->id}"));

        $this->assertNull($this->notificationFor("expiry_soon:batch:{$fine->id}"));
        $this->assertNull($this->notificationFor("expiry_passed:batch:{$fine->id}"));
    }

    public function test_a_fully_consumed_batch_never_alerts(): void
    {
        $product = $this->makeProduct(['has_batch' => true, 'min_stock' => 0, 'stock_quantity' => 0]);

        $this->makeBatch($product, 'EXP-SOLD', now()->subDays(2), ['quantity_sold' => 10]);

        $this->assertSame(0, $this->user->notifications()->count());

        // The sweep must ignore it too — there is no stock left to spoil.
        $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(0, $this->user->notifications()->count());
    }

    // ----------------------------------------------------------------- sweep

    public function test_sweep_surfaces_an_expiry_that_drifted_into_its_window_without_a_save(): void
    {
        $product = $this->makeProduct(['has_batch' => true, 'min_stock' => 0, 'stock_quantity' => 0]);
        $batch = $this->makeBatch($product, 'DRIFT-EXPIRY', now()->addDays(45));

        // Far outside the 30-day warning window, so creation stayed silent.
        $this->assertNull($this->notificationFor("expiry_soon:batch:{$batch->id}"));

        // Time passes without anybody touching the row...
        $this->travelTo(now()->addDays(20));

        // ...and simply opening the inbox re-evaluates the real position.
        $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertNotNull($this->notificationFor("expiry_soon:batch:{$batch->id}"));
    }

    public function test_sweep_picks_up_a_reorder_threshold_raised_without_a_sale(): void
    {
        $product = $this->makeProduct(['min_stock' => 0, 'stock_quantity' => 5]);

        // Raising the threshold changes no stock quantity, so the stock hook
        // (which keys off `stock_quantity`) correctly stays silent...
        $product->update(['min_stock' => 10]);
        $this->assertNull($this->notificationFor("stock_low:product:{$product->id}"));

        // ...and fetching notifications catches up on the real position.
        $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertNotNull($this->notificationFor("stock_low:product:{$product->id}"));
    }

    public function test_sweep_does_not_depend_on_the_badge_poll(): void
    {
        $product = $this->makeProduct(['min_stock' => 0, 'stock_quantity' => 5]);
        $product->update(['min_stock' => 10]);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk();

        $this->assertNull($this->notificationFor("stock_low:product:{$product->id}"));
    }

    // --------------------------------------------------------- purchase order

    public function test_purchase_order_approval_notifies_while_creation_stays_silent(): void
    {
        $supplier = Supplier::create([
            'business_id' => $this->business->id,
            'name' => 'Notify Supplier',
        ]);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-NOTIFY-1',
            'status' => 'draft',
            'total_amount' => 120,
        ]);

        // Drafts are work-in-progress, not actionable documents.
        $this->assertNull($this->notificationFor("po:{$po->id}"));

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'ordered'])
            ->assertOk();

        $this->assertNotNull($this->notificationFor("po:{$po->id}"));
    }

    // -------------------------------------------------------------- invoice

    public function test_open_invoice_notifies_and_a_paid_one_stays_silent(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'type' => 'individual',
            'name' => 'Notify Customer',
        ]);

        $open = $this->postInvoice($customer, 'unpaid');
        $open->assertCreated();
        $this->assertNotNull($this->notificationFor('invoice:'.$open->json('id')));

        $paid = $this->postInvoice($customer, 'paid');
        $paid->assertCreated();
        $this->assertNull($this->notificationFor('invoice:'.$paid->json('id')));
    }

    // ------------------------------------------------------- read state / badge

    public function test_mark_read_and_mark_all_read_update_the_unread_badge(): void
    {
        $first = $this->seedNotification('first');
        $second = $this->seedNotification('second');

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);

        $this->patchJson("/api/v1/notifications/{$first->id}")
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notification.read', true);

        $this->assertNotNull($first->refresh()->read_at);
        $this->assertNull($second->refresh()->read_at);

        $this->patchJson('/api/v1/notifications/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($second->refresh()->read_at);
        $this->assertSame(0, $this->user->unreadNotifications()->count());
        // Marking read keeps the history — it only clears the badge.
        $this->assertSame(2, $this->user->notifications()->count());
    }

    public function test_clear_empties_the_inbox(): void
    {
        $this->seedNotification('first');
        $this->seedNotification('second');

        $this->deleteJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->user->notifications()->count());

        // Clearing an already-empty inbox is not an error.
        $this->deleteJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_marking_an_already_read_notification_is_idempotent(): void
    {
        $first = $this->seedNotification('first');

        $this->patchJson("/api/v1/notifications/{$first->id}")->assertOk();
        $this->patchJson("/api/v1/notifications/{$first->id}")->assertOk()->assertJsonPath('unread_count', 0);

        $this->assertSame(1, $this->user->notifications()->count());
    }

    // ------------------------------------------------------------ tenancy

    public function test_a_notification_owned_by_someone_else_is_a_404(): void
    {
        $other = User::create([
            'business_id' => $this->business->id,
            'name' => 'Other Admin',
            'username' => 'notify-other',
            'email' => 'notify-other@example.com',
            'password' => Hash::make('StrongPass9!'),
            'role' => 'admin',
        ]);

        $other->notify($this->makeAlert('secret'));
        $foreignId = $other->notifications()->first()->id;

        $this->patchJson("/api/v1/notifications/{$foreignId}")->assertNotFound();

        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_a_non_uuid_notification_id_is_a_404_not_a_500(): void
    {
        $this->patchJson('/api/v1/notifications/not-a-uuid')->assertNotFound();
    }

    public function test_notification_endpoints_require_an_authenticated_user(): void
    {
        // Drop the guard user that setUp() installed for the rest of the suite.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/v1/notifications/read')->assertUnauthorized();
        $this->deleteJson('/api/v1/notifications')->assertUnauthorized();
    }

    // -------------------------------------------------------------- helpers

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => 'Tracked Product',
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'tax_rate' => 0,
            'unit' => 'pcs',
            'min_stock' => 0,
            'stock_quantity' => 0,
            'has_batch' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function makeBatch(Product $product, string $number, mixed $expiry, array $overrides = []): ProductBatch
    {
        return ProductBatch::create(array_merge([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_number' => $number,
            'quantity' => 10,
            'quantity_sold' => 0,
            'expiry_date' => $expiry,
            'cost_per_unit' => 4,
            'is_active' => true,
        ], $overrides));
    }

    private function postInvoice(Customer $customer, string $paymentStatus): TestResponse
    {
        return $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'status' => 'sent',
            'payment_status' => $paymentStatus,
            'items' => [
                [
                    'name' => 'Service Item',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ],
            ],
        ]);
    }

    private function makeAlert(string $tag): AppNotification
    {
        return new AppNotification(
            tag: $tag,
            severity: 'info',
            title: ['en' => 'Title', 'ar' => 'Title'],
            message: ['en' => 'Body', 'ar' => 'Body'],
        );
    }

    private function seedNotification(string $tag): DatabaseNotification
    {
        $this->user->notify($this->makeAlert($tag));

        return $this->user->notifications()->where('data->tag', $tag)->firstOrFail();
    }

    private function notificationFor(string $tag): ?DatabaseNotification
    {
        return $this->user->notifications()->where('data->tag', $tag)->first();
    }

    private function countFor(string $tag): int
    {
        return $this->user->notifications()->where('data->tag', $tag)->count();
    }
}
