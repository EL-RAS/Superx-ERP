<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingModuleTest extends TestCase
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
            'name_ar' => 'سوبر ماركت',
            'allowed_modules' => ['inventory', 'pos', 'accounting', 'crm'],
        ]);

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Accounting Module Retail',
            'slug' => 'accounting-module-retail',
            'status' => 'active',
        ]);

        $this->user = $this->makeUser($this->business, 'admin');
        Sanctum::actingAs($this->user);
    }

    private function makeUser(Business $business, string $role, string $suffix = ''): User
    {
        return User::create([
            'business_id' => $business->id,
            'name' => ucfirst($role).' User '.$suffix,
            'username' => $role.'-'.$suffix.Str::random(4),
            'email' => $suffix.$role.'-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function makeCustomer(string $name = 'AR Customer'): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id,
            'name' => $name,
        ]);
    }

    private function makeSupplier(string $name = 'AP Supplier'): Supplier
    {
        return Supplier::create([
            'business_id' => $this->business->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $name = 'GRN Product'): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'created_by' => $this->user->id,
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(Str::random(6)),
            'price' => 10,
            'cost' => 2,
            'tax_rate' => 0,
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);
    }

    private function postInvoice(Customer $customer, string $status = 'sent', float $quantity = 2, float $unitPrice = 10): TestResponse
    {
        return $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'status' => $status,
            'items' => [
                [
                    'name' => 'Service Item',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 5,
                ],
            ],
        ]);
    }

    private function makeOrderedPo(Supplier $supplier, float $total = 100, ?Product $product = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-'.strtoupper(Str::random(6)),
            'status' => 'ordered',
            'total_amount' => $total,
        ]);

        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product?->id,
            'name' => 'Ordered Item',
            'quantity' => 1,
            'unit_cost' => $total,
            'total' => $total,
        ]);

        return $po;
    }

    private function accountId(string $code): ?int
    {
        return Account::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('code', $code)
            ->value('id');
    }

    private function entryLines(int $entryId)
    {
        return JournalEntryLine::withoutGlobalScope(BusinessScope::class)
            ->where('journal_entry_id', $entryId)
            ->get();
    }

    public function test_receivables_summarizes_open_invoices_per_customer(): void
    {
        $customer = $this->makeCustomer();

        // Two open invoices: 2×10+5%=21 each → 42 outstanding.
        $this->postInvoice($customer)->assertStatus(201);
        $this->postInvoice($customer, 'sent', 2, 10)->assertStatus(201);
        // A paid invoice must be excluded (balance zero).
        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['name' => 'Paid Item', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/accounting/ar');
        $response->assertOk();

        $this->assertSame('1040', $response->json('account_code'));
        $this->assertSame(42.0, round((float) $response->json('total_outstanding'), 2));
        $customers = $response->json('customers');
        $this->assertCount(1, $customers);
        $this->assertSame($customer->name, $customers[0]['name']);
        $this->assertSame(2, $customers[0]['open_invoices_count']);
        $this->assertSame(42.0, round((float) $customers[0]['outstanding'], 2));
        $this->assertCount(2, $customers[0]['invoices']);
        $this->assertSame(21.0, round((float) $customers[0]['invoices'][0]['balance'], 2));
        $this->assertSame(0.0, round((float) $customers[0]['invoices'][0]['paid'], 2));
    }

    public function test_receivables_excludes_draft_and_void_invoices(): void
    {
        $customer = $this->makeCustomer();

        $draft = $this->postInvoice($customer, 'draft');
        $draft->assertStatus(201);

        $sent = $this->postInvoice($customer, 'sent');
        $sent->assertStatus(201);

        $this->postJson("/api/v1/invoices/{$sent->json('id')}/void")->assertStatus(200);

        $response = $this->getJson('/api/v1/accounting/ar');
        $response->assertOk();
        $this->assertSame(0.0, round((float) $response->json('total_outstanding'), 2));
        $this->assertSame([], $response->json('customers'));
    }

    public function test_payables_summarize_open_goods_receipts(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();

        // 350 in unpaid credit-method receipts → payable.
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'received_quantity' => 4, 'unit_cost' => 25]],
        ])->assertStatus(201);
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'received_quantity' => 5, 'unit_cost' => 50]],
        ])->assertStatus(201);

        // A cash-paid receipt leaves no open payable.
        $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'received_quantity' => 1, 'unit_cost' => 500]],
        ])->assertStatus(201);

        // Approved POs with no goods received contribute nothing.
        $this->makeOrderedPo($supplier, 500);

        $response = $this->getJson('/api/v1/accounting/ap');
        $response->assertOk();

        $this->assertSame('2010', $response->json('account_code'));
        $this->assertSame(350.0, round((float) $response->json('total_outstanding'), 2));
        $suppliers = $response->json('suppliers');
        $this->assertCount(1, $suppliers);
        $this->assertSame($supplier->name, $suppliers[0]['name']);
        $this->assertSame(2, $suppliers[0]['open_receipts_count']);
        $this->assertSame(350.0, round((float) $suppliers[0]['outstanding'], 2));
        $this->assertCount(2, $suppliers[0]['receipts']);
        $this->assertSame([], $suppliers[0]['orders']);
        $this->assertSame(0, $suppliers[0]['open_orders_count']);
    }

    public function test_payable_statement_lists_receipts_with_advance_payments(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();

        // Approved PO (100) → no payable yet.
        $po = $this->makeOrderedPo($supplier, 100, $product);

        // Advance payment of 40 against the ordered PO.
        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 40,
            'method' => 'cash',
        ])->assertStatus(201);

        // Goods arrive on credit (100) → payable 100 − advance 40 = 60.
        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 100],
            ],
        ])->assertStatus(201);

        $statement = $this->getJson("/api/v1/accounting/ap/{$supplier->id}/statement");
        $statement->assertOk();

        $this->assertSame(60.0, round((float) $statement->json('total_outstanding'), 2));
        $orders = $statement->json('orders');
        $this->assertCount(1, $orders);
        $this->assertSame('goods_receipt', $orders[0]['kind']);
        $this->assertSame(60.0, round((float) $orders[0]['balance'], 2));
        $this->assertSame(40.0, round((float) $orders[0]['paid'], 2));
        $this->assertSame(40.0, round((float) $orders[0]['payments'][0]['amount'], 2));
        $this->assertSame('cash', $orders[0]['payments'][0]['method']);
    }

    public function test_collect_invoice_payment_posts_gl_and_reduces_receivable(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->postInvoice($customer)->assertStatus(201)->json();

        $pay = $this->postJson("/api/v1/accounting/ar/{$invoice['id']}/pay", [
            'amount' => 10,
            'method' => 'cash',
            'reference_number' => 'REF-001',
        ]);
        $pay->assertStatus(201);
        $payment = $pay->json('payment');
        $this->assertSame('completed', $payment['status']);
        $this->assertSame(10.0, round((float) $payment['amount'], 2));

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice['id'],
            'status' => 'completed',
            'amount' => 10.0,
        ]);

        $entry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'invoice_payment')
            ->where('reference_id', $payment['id'])
            ->first();
        $this->assertNotNull($entry);

        $lines = $this->entryLines($entry->id);
        $this->assertSame(10.0, round((float) $lines->sum('debit'), 2));
        $this->assertSame(10.0, round((float) $lines->sum('credit'), 2));
        $this->assertSame(10.0, round((float) $lines->where('account_id', $this->accountId('1005'))->sum('debit'), 2));
        $this->assertSame(10.0, round((float) $lines->where('account_id', $this->accountId('1040'))->sum('credit'), 2));

        $ar = $this->getJson('/api/v1/accounting/ar');
        $ar->assertOk();
        $this->assertSame(11.0, round((float) $ar->json('total_outstanding'), 2));
        $this->assertSame(11.0, round((float) $ar->json('customers')[0]['invoices'][0]['balance'], 2));
        $this->assertSame(10.0, round((float) $ar->json('customers')[0]['invoices'][0]['paid'], 2));
    }

    public function test_pay_purchase_order_posts_gl_and_reduces_payable(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makeOrderedPo($supplier, 100, $product);

        $pay = $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 60,
            'method' => 'bank_transfer',
        ]);
        $pay->assertStatus(201);
        $payment = $pay->json();
        $this->assertSame('completed', $payment['status']);
        $this->assertSame(60.0, round((float) $payment['amount'], 2));

        $this->assertDatabaseHas('purchase_order_payments', [
            'purchase_order_id' => $po->id,
            'status' => 'completed',
            'amount' => 60.0,
        ]);

        $entry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment['id'])
            ->first();
        $this->assertNotNull($entry);

        $lines = $this->entryLines($entry->id);
        $this->assertSame(60.0, round((float) $lines->sum('debit'), 2));
        $this->assertSame(60.0, round((float) $lines->sum('credit'), 2));
        $this->assertSame(60.0, round((float) $lines->where('account_id', $this->accountId('2010'))->sum('debit'), 2));
        $this->assertSame(60.0, round((float) $lines->where('account_id', $this->accountId('1020'))->sum('credit'), 2));

        // Prepayment: goods haven't arrived, so nothing is owed yet — the
        // supplier is simply prepaid.
        $ap = $this->getJson('/api/v1/accounting/ap');
        $ap->assertOk();
        $this->assertSame(0.0, round((float) $ap->json('total_outstanding'), 2));
        $this->assertSame([], $ap->json('suppliers'));
        $this->assertSame(-60.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));

        // Goods arrive on credit (100) → payable 100 − prepaid 60 = 40.
        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 100],
            ],
        ])->assertStatus(201);

        $apAfter = $this->getJson('/api/v1/accounting/ap');
        $apAfter->assertOk();
        $this->assertSame(40.0, round((float) $apAfter->json('total_outstanding'), 2));
        $this->assertSame(40.0, round((float) $apAfter->json('suppliers')[0]['receipts'][0]['balance'], 2));
        $this->assertSame(60.0, round((float) $apAfter->json('suppliers')[0]['receipts'][0]['paid'], 2));
    }

    public function test_purchase_order_pay_rejects_draft_and_reports_paid_remaining(): void
    {
        $supplier = $this->makeSupplier();
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-DRAFT-1',
            'status' => 'draft',
            'total_amount' => 100,
        ]);
        PurchaseOrderItem::create([
            'business_id' => $this->business->id,
            'purchase_order_id' => $po->id,
            'name' => 'Draft Item',
            'quantity' => 1,
            'unit_cost' => 100,
            'total' => 100,
        ]);

        $show = $this->getJson("/api/v1/purchase-orders/{$po->id}");
        $show->assertOk();
        $this->assertSame(0.0, round((float) $show->json('paid_amount'), 2));
        $this->assertSame(100.0, round((float) $show->json('remaining_amount'), 2));

        // Draft orders cannot receive advance payments.
        $rejects = $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ]);
        $rejects->assertStatus(422);
        $this->assertSame(
            'Payments cannot be recorded for draft orders. Please approve the order first.',
            $rejects->json('message')
        );

        // Approve, then pay 40: paid/remaining must be recalculated on the PO record.
        $po->update(['status' => 'ordered']);
        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 40,
            'method' => 'cash',
        ])->assertStatus(201);

        $after = $this->getJson("/api/v1/purchase-orders/{$po->id}");
        $after->assertOk();
        $this->assertSame(40.0, round((float) $after->json('paid_amount'), 2));
        $this->assertSame(60.0, round((float) $after->json('remaining_amount'), 2));

        // Supplier ledger shows the payment as a Debit against the supplier
        // (prepayment) — no PO row until goods are actually received.
        $ledger = $this->getJson("/api/v1/suppliers/{$supplier->id}/ledger");
        $ledger->assertOk();
        $rows = $ledger->json('rows');
        $paymentRow = collect($rows)->firstWhere('kind', 'payment');
        $this->assertNotNull($paymentRow);
        $this->assertSame(40.0, round((float) $paymentRow['debit'], 2));
        $this->assertSame(-40.0, round((float) $paymentRow['balance'], 2));
        $this->assertNull(collect($rows)->firstWhere('kind', 'purchase_order'));
        // Supplier balance is the running ledger total: -40.
        $this->assertSame(-40.0, round((float) $this->getJson("/api/v1/suppliers/{$supplier->id}")->json('balance'), 2));
    }

    public function test_direct_grn_payable_appears_in_ap_and_is_settled_via_accounting_route(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();

        // Pay-later direct GRN → payable on the supplier.
        $grn = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'received_quantity' => 5, 'unit_cost' => 3]],
        ])->assertStatus(201)->json();

        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $supplierRow = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertNotNull($supplierRow);
        $this->assertSame(1, $supplierRow['open_receipts_count']);
        $this->assertSame(15.0, round((float) $supplierRow['outstanding'], 2));
        $this->assertCount(1, $supplierRow['receipts']);
        $this->assertSame($grn['receipt_number'], $supplierRow['receipts'][0]['receipt_number']);
        $this->assertSame(15.0, round((float) $supplierRow['receipts'][0]['balance'], 2));

        // Settlement via the accounting-scoped route (permission:accounting).
        $pay = $this->postJson("/api/v1/accounting/ap/receipts/{$grn['id']}/pay", [
            'amount' => 15,
            'method' => 'bank_transfer',
            'reference_number' => 'GRN-SETTLE-1',
        ]);
        $pay->assertStatus(201);
        $payment = $pay->json();
        $this->assertSame('completed', $payment['status']);
        $this->assertSame(15.0, round((float) $payment['amount'], 2));
        $this->assertSame($grn['id'], $payment['goods_receipt_id']);

        $this->assertDatabaseHas('purchase_order_payments', [
            'goods_receipt_id' => $grn['id'],
            'status' => 'completed',
            'amount' => 15.0,
        ]);

        // Dr 2010 / Cr 1020, balanced.
        $entry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment['id'])
            ->first();
        $this->assertNotNull($entry);
        $lines = $this->entryLines($entry->id);
        $this->assertSame(15.0, round((float) $lines->sum('debit'), 2));
        $this->assertSame(15.0, round((float) $lines->sum('credit'), 2));
        $this->assertSame(15.0, round((float) $lines->where('account_id', $this->accountId('2010'))->sum('debit'), 2));
        $this->assertSame(15.0, round((float) $lines->where('account_id', $this->accountId('1020'))->sum('credit'), 2));

        // Supplier dropped from AP after full settlement.
        $apAfter = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertCount(0, collect($apAfter['suppliers'])->where('supplier_id', $supplier->id));
    }

    public function test_direct_grn_pay_route_rejects_cross_business_receipt(): void
    {
        $otherBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->business->business_type_id,
            'name' => 'Other Accounting Retail',
            'slug' => 'other-accounting-retail-2',
            'status' => 'active',
        ]);
        $this->makeUser($otherBusiness, 'admin', 'other2');

        $otherSupplier = Supplier::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Supplier 2',
            'is_active' => true,
        ]);
        $otherProduct = Product::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'created_by' => $this->user->id,
            'name' => 'Other Product',
            'sku' => 'SKU-OTHER-2',
            'price' => 10,
            'cost' => 2,
            'tax_rate' => 0,
            'has_batch' => false,
            'is_active' => true,
            'stock_quantity' => 0,
        ]);

        $otherSupplier->refresh();

        $receipt = GoodsReceipt::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'supplier_id' => $otherSupplier->id,
            'receipt_number' => 'GRN-OTHER-2',
            'payment_method' => null,
            'status' => 'received',
            'total_amount' => 20,
            'received_at' => now(),
        ]);

        $this->postJson("/api/v1/accounting/ap/receipts/{$receipt->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertStatus(404);
    }

    public function test_cross_business_pay_and_statement_return_404(): void
    {
        $otherBusiness = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $this->business->business_type_id,
            'name' => 'Other Accounting Retail',
            'slug' => 'other-accounting-retail',
            'status' => 'active',
        ]);
        $this->makeUser($otherBusiness, 'admin', 'other');

        $otherCustomer = Customer::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Customer',
        ]);
        $otherInvoice = Invoice::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV-OTHER',
            'status' => 'sent',
            'payment_status' => 'unpaid',
            'subtotal' => 10,
            'tax_amount' => 0,
            'net_amount' => 10,
            'total_amount' => 10,
            'currency' => 'JOD',
        ]);
        $otherSupplier = Supplier::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Supplier',
            'is_active' => true,
        ]);
        $otherPo = PurchaseOrder::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => $otherBusiness->id,
            'supplier_id' => $otherSupplier->id,
            'user_id' => $this->user->id,
            'order_number' => 'PO-OTHER',
            'status' => 'ordered',
            'total_amount' => 100,
        ]);

        // Route-model binding (BusinessScope) already 404s foreign invoices/POs,
        // and the controllers' explicit business guards back it up.
        $this->postJson("/api/v1/accounting/ar/{$otherInvoice->id}/pay", [
            'amount' => 5, 'method' => 'cash',
        ])->assertStatus(404);
        $this->postJson("/api/v1/accounting/ap/{$otherPo->id}/pay", [
            'amount' => 5, 'method' => 'cash',
        ])->assertStatus(404);
        $this->getJson("/api/v1/accounting/ap/{$otherSupplier->id}/statement")->assertStatus(404);
    }

    public function test_non_accounting_role_is_forbidden_on_ar_ap(): void
    {
        $cashier = $this->makeUser($this->business, 'cashier', 'gate');
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/accounting/ar')->assertForbidden();
        $this->getJson('/api/v1/accounting/ap')->assertForbidden();

        // Binding resolves first, then the permission gate denies accounting reads.
        $this->getJson('/api/v1/accounting/ap/'.$this->makeSupplier()->id.'/statement')->assertForbidden();
    }

    public function test_po_approval_has_no_financial_effect_advance_reconciles_on_grn(): void
    {
        // The user's core scenario: PO approval must be free of financial
        // effect, a pre-GRN payment must reconcile against the eventual credit
        // receipt, and the supplier owes exactly the difference.
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makeOrderedPo($supplier, 20, $product);

        // Approved PO → nothing: no journal, no payable, no ledger row, balance 0.
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame(0.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));
        $this->assertSame([], $this->getJson("/api/v1/suppliers/{$supplier->id}/ledger")->json('rows'));
        $this->assertSame(0.0, round((float) $this->getJson('/api/v1/accounting/ap')->json('total_outstanding'), 2));

        // Advance payment 10 → supplier prepaid by 10 (negative balance).
        $pay = $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ]);
        $pay->assertStatus(201);
        $this->assertSame(-10.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));

        $ledger = $this->getJson("/api/v1/suppliers/{$supplier->id}/ledger")->assertOk()->json();
        $paymentRow = collect($ledger['rows'])->firstWhere('kind', 'payment');
        $this->assertNotNull($paymentRow);
        $this->assertSame(10.0, round((float) $paymentRow['debit'], 2));
        $this->assertSame(-10.0, round((float) $paymentRow['balance'], 2));

        // Goods arrive on credit (20) the next day → supplier balance -10 + 20 = +10.
        // (Traveling the clock keeps the ledger chronologically ordered: the
        // advance is recorded first, the receipt second.)
        $this->travel(1)->day();
        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 20],
            ],
        ])->assertStatus(201);

        $expectedBalance = 10.0;
        $this->assertSame($expectedBalance, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));

        $ledger = $this->getJson("/api/v1/suppliers/{$supplier->id}/ledger")->assertOk()->json();
        $this->assertSame($expectedBalance, round((float) $ledger['balance'], 2));
        $grnRow = collect($ledger['rows'])->firstWhere('kind', 'goods_receipt');
        $this->assertNotNull($grnRow);
        $this->assertSame(20.0, round((float) $grnRow['credit'], 2));
        $this->assertSame(10.0, round((float) $grnRow['balance'], 2));
        $this->assertNull(collect($ledger['rows'])->firstWhere('kind', 'purchase_order'));

        // AP subledger agrees: receipt 20 − prepaid 10 = 10 owed.
        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $row = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertNotNull($row);
        $this->assertSame($expectedBalance, round((float) $row['outstanding'], 2));
        $this->assertCount(1, $row['receipts']);
        $this->assertSame(10.0, round((float) $row['receipts'][0]['paid'], 2));
        $this->assertSame($expectedBalance, round((float) $row['receipts'][0]['balance'], 2));

        // GL: credits 2010 by 20 for the receipt, debits 2010 by 10 for the
        // payment; both entries balanced, so 2010 nets to a +10 credit.
        $grnEntry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'goods_receipt')
            ->first();
        $this->assertNotNull($grnEntry);
        $grnLines = $this->entryLines($grnEntry->id);
        $this->assertSame(20.0, round((float) $grnLines->sum('debit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->sum('credit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->where('account_id', $this->accountId('1030'))->sum('debit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->where('account_id', $this->accountId('2010'))->sum('credit'), 2));

        $payEntry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'supplier_payment')
            ->first();
        $this->assertNotNull($payEntry);
        $payLines = $this->entryLines($payEntry->id);
        $this->assertSame(10.0, round((float) $payLines->sum('debit'), 2));
        $this->assertSame(10.0, round((float) $payLines->sum('credit'), 2));
        $this->assertSame(10.0, round((float) $payLines->where('account_id', $this->accountId('2010'))->sum('debit'), 2));
        $this->assertSame(10.0, round((float) $payLines->where('account_id', $this->accountId('1005'))->sum('credit'), 2));
    }

    public function test_grn_pay_now_settles_po_advance_and_balances_supplier_to_zero(): void
    {
        // Core feature: a PO advanced by 10 of its 20 total, then received with
        // pay-now = the net due (10), leaves the supplier ledger at exactly 0.00.
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makeOrderedPo($supplier, 20, $product);

        // Record the advance against the approved PO (no goods received yet).
        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertStatus(201);
        $this->assertSame(-10.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));
        $this->assertSame(10.0, round((float) $this->getJson('/api/v1/purchase-orders/'.$po->id)->json('remaining_amount'), 2));

        // Receive the full order the next day, paying only the net due up front.
        $this->travel(1)->day();
        $receipt = $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'pay_now_amount' => 10,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 20],
            ],
        ])->assertStatus(201)->json();

        // The seller is now fully settled: balance, ledger running total and AP
        // all read 0.00, and the PO is fully paid.
        $this->assertSame(0.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));

        $ledger = $this->getJson("/api/v1/suppliers/{$supplier->id}/ledger")->assertOk()->json();
        $this->assertSame(0.0, round((float) $ledger['balance'], 2));
        $grnRow = collect($ledger['rows'])->firstWhere('kind', 'goods_receipt');
        $this->assertNotNull($grnRow);
        $this->assertSame(10.0, round((float) $grnRow['credit'], 2));
        $this->assertSame(0.0, round((float) $grnRow['balance'], 2));

        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $this->assertSame(0.0, round((float) $ap['total_outstanding'], 2));
        $row = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertSame(0.0, round((float) ($row['outstanding'] ?? 0), 2));

        $poAfter = $this->getJson('/api/v1/purchase-orders/'.$po->id)->json();
        $this->assertSame(20.0, round((float) $poAfter['paid_amount'], 2));
        $this->assertSame(0.0, round((float) $poAfter['remaining_amount'], 2));

        // Receipt persisted its pay-now portion and a direct payment row for it.
        $this->assertSame('received', $receipt['status']);
        $this->assertSame(10.0, round((float) $receipt['pay_now_amount'], 2));
        $this->assertSame(10.0, round((float) $receipt['payable_credit'], 2));
        $this->assertDatabaseCount('purchase_order_payments', 2);

        // GL split: the single goods_receipt entry debits 1030 by the full 20,
        // credits cash by the 10 paid now and the remaining 10 to the payable,
        // so 2010 nets to zero against the advance's debit.
        $grnEntry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'goods_receipt')
            ->first();
        $this->assertNotNull($grnEntry);
        $grnLines = $this->entryLines($grnEntry->id);
        $this->assertSame(20.0, round((float) $grnLines->sum('debit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->sum('credit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->where('account_id', $this->accountId('1030'))->sum('debit'), 2));
        $this->assertSame(10.0, round((float) $grnLines->where('account_id', $this->accountId('1005'))->sum('credit'), 2));
        $this->assertSame(10.0, round((float) $grnLines->where('account_id', $this->accountId('2010'))->sum('credit'), 2));
        $this->assertSame(
            0.0,
            round((float) JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('account_id', $this->accountId('2010'))
                ->sum('credit') - JournalEntryLine::withoutGlobalScope(BusinessScope::class)
                ->where('account_id', $this->accountId('2010'))
                ->sum('debit'), 2)
        );
    }

    public function test_grn_pay_now_cannot_exceed_po_remaining_after_advance(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makeOrderedPo($supplier, 20, $product);

        $this->postJson("/api/v1/accounting/ap/{$po->id}/pay", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertStatus(201);

        // Only the net 10 remains after the advance; 15 is an overpay → 422
        // and the whole receive transaction rolls back.
        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'pay_now_amount' => 15,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 20],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Payment amount (15) exceeds remaining balance (10).');

        // Nothing persisted: no receipt, no GL entry, PO untouched, item not received.
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertSame(1, JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->count());
        $poFresh = $po->fresh();
        $this->assertSame('ordered', $poFresh->status);
        $this->assertSame(0.0, round((float) $poFresh->items()->first()->received_quantity, 2));

        // Net-due boundary (10) is accepted.
        $this->postJson('/api/v1/goods-receipts', [
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'pay_now_amount' => 10,
            'items' => [
                ['product_id' => $product->id, 'purchase_order_item_id' => $po->items()->first()->id, 'received_quantity' => 1, 'unit_cost' => 20],
            ],
        ])->assertStatus(201);
        $this->assertSame(0.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));
    }

    public function test_direct_grn_partial_cash_pay_now_leaves_payable_leg(): void
    {
        // Direct (no PO) partial cash receipt: 20 received, 5 paid at the door
        // → the remaining 15 stays open on 2010 and the supplier owes 15.
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();

        $receipt = $this->postJson('/api/v1/goods-receipts', [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'pay_now_amount' => 5,
            'items' => [
                ['product_id' => $product->id, 'received_quantity' => 10, 'unit_cost' => 2],
            ],
        ])->assertStatus(201)->json();

        $this->assertSame(5.0, round((float) $receipt['pay_now_amount'], 2));
        $this->assertSame(15.0, round((float) $receipt['payable_credit'], 2));

        $grnEntry = JournalEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $this->business->id)
            ->where('reference_type', 'goods_receipt')
            ->first();
        $this->assertNotNull($grnEntry);
        $grnLines = $this->entryLines($grnEntry->id);
        $this->assertSame(20.0, round((float) $grnLines->sum('debit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->sum('credit'), 2));
        $this->assertSame(20.0, round((float) $grnLines->where('account_id', $this->accountId('1030'))->sum('debit'), 2));
        $this->assertSame(5.0, round((float) $grnLines->where('account_id', $this->accountId('1005'))->sum('credit'), 2));
        $this->assertSame(15.0, round((float) $grnLines->where('account_id', $this->accountId('2010'))->sum('credit'), 2));

        $this->assertSame(15.0, round((float) $this->getJson('/api/v1/suppliers/'.$supplier->id)->json('balance'), 2));
        $ap = $this->getJson('/api/v1/accounting/ap')->assertOk()->json();
        $row = collect($ap['suppliers'])->firstWhere('supplier_id', $supplier->id);
        $this->assertSame(15.0, round((float) $row['outstanding'], 2));
        $this->assertCount(1, $row['receipts']);
        $this->assertSame(0.0, round((float) $row['receipts'][0]['paid'], 2));
        $this->assertSame(15.0, round((float) $row['receipts'][0]['balance'], 2));
    }
}
