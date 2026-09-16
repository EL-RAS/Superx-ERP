<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\ActivationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankReconciliationController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessTypeController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Clinic\AppointmentController;
use App\Http\Controllers\Api\V1\Clinic\DentalChartController;
use App\Http\Controllers\Api\V1\Clinic\InsuranceClaimController;
use App\Http\Controllers\Api\V1\Clinic\LabOrderController;
use App\Http\Controllers\Api\V1\Clinic\MedicalRecordController;
use App\Http\Controllers\Api\V1\Clinic\PrescriptionController;
use App\Http\Controllers\Api\V1\Clinic\TreatmentPlanController;
use App\Http\Controllers\Api\V1\Clinic\TreatmentProcedureController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FiscalYearController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\Jewelry\GoldRateController;
use App\Http\Controllers\Api\V1\Jewelry\GoldRateLogController;
use App\Http\Controllers\Api\V1\Jewelry\PoliceBookEntryController;
use App\Http\Controllers\Api\V1\Jewelry\RepairTicketController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlatformLeadController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use App\Http\Controllers\Api\V1\ProductBatchController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\Restaurant\KitchenOrderController;
use App\Http\Controllers\Api\V1\Restaurant\ReservationController;
use App\Http\Controllers\Api\V1\Restaurant\RestaurantTableController;
use App\Http\Controllers\Api\V1\ReturnExchangeController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SerialNumberController;
use App\Http\Controllers\Api\V1\ServiceTicketController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TenantAuthController;
use App\Http\Controllers\Api\V1\UsersController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarrantyClaimController;
use App\Http\Controllers\Api\V1\ZReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ─── Public Routes ───────────────────────────────────────────
    Route::get('/business-types', BusinessTypeController::class);
    Route::get('/businesses', BusinessController::class);
    // Self-service registration is disabled — B2B onboarding only:
    // prospects submit a lead, the SuperX owner provisions tenants manually.
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::post('/login', [AuthController::class, 'login']);

    // ─── B2B activation & tenant auth (no auth required) ─────────
    Route::get('/activate/{token}', [ActivationController::class, 'show'])->name('activate.show');
    Route::post('/activate', [ActivationController::class, 'store'])->name('activate.store');
    Route::post('/tenant-login', [TenantAuthController::class, 'login'])->name('tenant.login');

    // ─── SuperX Owner Portal (platform administration) ──────────
    // Strictly guarded: `superx_owner` role or the SUPERX_OWNER_SECRET env key.
    Route::middleware('platform.owner')->prefix('platform')->group(function () {
        Route::get('/tenants', [PlatformTenantController::class, 'index'])->name('platform.tenants.index');
        Route::post('/tenants', [PlatformTenantController::class, 'store'])->name('platform.tenants.store');
        Route::get('/tenants/{business}', [PlatformTenantController::class, 'show'])->name('platform.tenants.show');
        Route::match(['put', 'patch'], '/tenants/{business}', [PlatformTenantController::class, 'update'])->name('platform.tenants.update');

        Route::get('/leads', [PlatformLeadController::class, 'index'])->name('platform.leads.index');
        Route::post('/leads/{lead}/approve', [PlatformLeadController::class, 'approve'])->name('platform.leads.approve');
        Route::post('/leads/{lead}/reject', [PlatformLeadController::class, 'reject'])->name('platform.leads.reject');
    });

    // ─── Authenticated + Business Middleware ──────────────────────
    Route::middleware(['auth:sanctum', 'business'])->group(function () {

        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
        Route::post('/profile/password', [AuthController::class, 'changePassword'])->name('profile.password');

        // Bootstrap
        Route::get('/bootstrap', BootstrapController::class);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'dashboard'])->middleware('permission:dashboard');
        Route::get('/tenant/dashboard', [DashboardController::class, 'dashboard'])->middleware('permission:dashboard');
        Route::get('/dashboard/stats', DashboardController::class)->middleware('permission:dashboard');
        Route::get('/dashboard/supermarket', [DashboardController::class, 'supermarket'])->middleware('permission:dashboard');

        // ─── Users & Roles ──────────────────────────────────────────
        Route::middleware('permission:users_roles')->group(function () {
            Route::apiResource('users', UsersController::class);
            Route::get('/roles/matrix', [RoleController::class, 'matrix'])->name('roles.matrix');
            Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.updatePermissions');
            Route::apiResource('roles', RoleController::class)->except(['edit', 'create']);
        });

        // ─── Settings ───────────────────────────────────────────────
        Route::get('/businesses/settings', [BusinessController::class, 'settings'])->name('businesses.settings.show')
            ->middleware('permission:settings');
        Route::put('/businesses/settings', [BusinessController::class, 'updateSettings'])->name('businesses.settings')
            ->middleware('permission:settings');

        // ─── Products & Categories (read routes shared with POS) ─────
        Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:inventory.view,pos.view');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])->middleware('permission:inventory.view,pos.view');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:inventory.create');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->middleware('permission:inventory.edit');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:inventory.delete');

        Route::post('/products/barcode/lookup', [ProductController::class, 'byBarcode'])->middleware('permission:inventory.view,pos.view');
        Route::post('/products/import', [ProductController::class, 'import'])->middleware('permission:inventory.create');
        Route::post('/products/quick-add', [ProductController::class, 'quickAdd'])->middleware('permission:inventory.create,pos.create');
        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:inventory.view,pos.view');
        Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission:inventory.view,pos.view');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:inventory.create');
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->middleware('permission:inventory.edit');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:inventory.delete');

        // ─── CRM / Customers ─────────────────────────────────────────
        // Customer CRUD, lookup and statements stay live for Sales/POS/
        // Accounting/AR even when the CRM module is disabled (V1). Only
        // CRM-exclusive surface (segments, loyalty, campaigns, webhook)
        // is gated behind config('features.crm_enabled').
        Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:crm.view');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:crm.view');
        Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])->middleware('permission:crm.view');
        Route::post('/customers/lookup', [CustomerController::class, 'lookupByPhone'])->middleware('permission:crm.view,pos.view');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:crm.create');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:crm.edit');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:crm.delete');
        Route::get('/customers/segments/stats', [CustomerController::class, 'segments'])->middleware('permission:crm.view')->middleware('feature:crm_enabled');

        // ─── CRM-exclusive module (disabled in V1 via features.crm_enabled) ──
        Route::middleware('feature:crm_enabled')->group(function () {
            // ─── Loyalty ─────────────────────────────────────────────
            Route::prefix('loyalty')->as('loyalty.')->group(function () {
                Route::get('/cards', [LoyaltyController::class, 'cards'])->middleware('permission:crm.view,pos.view')->name('cards');
                Route::get('/cards/{loyaltyCard}', [LoyaltyController::class, 'showCard'])->middleware('permission:crm.view,pos.view')->name('cards.show');
                Route::post('/cards', [LoyaltyController::class, 'storeCard'])->middleware('permission:crm.create')->name('cards.store');
                Route::put('/cards/{loyaltyCard}', [LoyaltyController::class, 'updateCard'])->middleware('permission:crm.edit')->name('cards.update');
                Route::delete('/cards/{loyaltyCard}', [LoyaltyController::class, 'destroyCard'])->middleware('permission:crm.delete')->name('cards.destroy');
                Route::get('/transactions', [LoyaltyController::class, 'transactions'])->middleware('permission:crm.view,pos.view')->name('transactions');
                Route::post('/transactions', [LoyaltyController::class, 'storeTransaction'])->middleware('permission:crm.view,pos.view')->name('transactions.store');
                Route::post('/lookup', [LoyaltyController::class, 'lookupByPhone'])->middleware('permission:crm.view,pos.view')->name('lookup');
                Route::get('/config', [LoyaltyController::class, 'config'])->middleware('permission:crm.view,pos.view')->name('config');
            });

            // ─── Campaigns ────────────────────────────────────────────
            Route::middleware('permission:crm')->group(function () {
                Route::get('/campaigns', [CampaignController::class, 'index']);
                Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
                Route::post('/campaigns', [CampaignController::class, 'store']);
                Route::match(['put', 'patch'], '/campaigns/{campaign}', [CampaignController::class, 'update']);
                Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
                Route::post('/campaigns/{campaign}/send', [CampaignController::class, 'send']);
                Route::post('/campaigns/{campaign}/send-test', [CampaignController::class, 'sendTest']);
                Route::get('/campaigns/{campaign}/stats', [CampaignController::class, 'stats']);
            });

            // ─── Delivery Webhook ─────────────────────────────────────
            Route::post('/delivery/webhook', [CustomerController::class, 'deliveryWebhook']);
        });

        // ─── Sales / Invoices ────────────────────────────────────────
        Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:sales.view');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:sales.view');
        Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:sales.create');
        Route::match(['put', 'patch'], '/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:sales.lock');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:sales.delete');
        Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('permission:sales.view,sales.create')->name('invoices.pay');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:sales.lock')->name('invoices.void');
        Route::post('/invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->middleware('permission:sales.create')->name('invoices.duplicate');
        Route::patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus'])->middleware('permission:sales.view,sales.create,sales.edit')->name('invoices.updateStatus');

        // ─── Returns & Exchanges (clothing vertical) ───────────────────
        Route::middleware('permission:sales')->group(function () {
            Route::get('/returns-exchanges', [ReturnExchangeController::class, 'index'])->name('returns-exchanges.index');
            Route::get('/returns-exchanges/{returnExchange}', [ReturnExchangeController::class, 'show'])->name('returns-exchanges.show');
            Route::post('/returns-exchanges', [ReturnExchangeController::class, 'store'])->name('returns-exchanges.store');
            Route::get('/invoices/{invoice}/returnable', [ReturnExchangeController::class, 'returnable'])->name('invoices.returnable');
        });

        Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:sales.view,accounting.view');
        Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:sales.create');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:sales.view,accounting.view');
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware('permission:sales.delete')->name('payments.refund');

        // ─── POS / Shifts ────────────────────────────────────────────
        Route::middleware('permission:pos')->group(function () {
            Route::post('/promotions/apply', [PromotionController::class, 'apply'])->name('promotions.apply');
            Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
            Route::get('/shifts/active', [ShiftController::class, 'current'])->name('shifts.active');
            Route::get('/shifts/current', [ShiftController::class, 'current'])->name('shifts.current');
            Route::get('/shifts/last-closed', [ShiftController::class, 'lastClosed'])->name('shifts.lastClosed');
            Route::post('/shifts/start', [ShiftController::class, 'store'])->name('shifts.start');
            Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
            Route::get('/shifts/{shift}/z-report', [ShiftController::class, 'zReport'])->name('shifts.zReport');
        });

        // ─── Shift Management (admin-only, explicit pos.shifts) ─────
        Route::middleware('permission:pos.shifts')->group(function () {
            Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
            Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
            Route::get('/z-reports', [ZReportController::class, 'index'])->name('z-reports.index');
            Route::get('/z-reports/{zReport}', [ZReportController::class, 'show'])->name('z-reports.show');
        });

        // ─── Purchases / Suppliers / PO / GRN ────────────────────────
        Route::middleware('permission:purchases')->group(function () {
            Route::apiResource('suppliers', SupplierController::class);
            Route::get('/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])->name('suppliers.ledger');
            Route::get('/suppliers/{supplier}/products', [SupplierController::class, 'products'])->name('suppliers.products.index');
            Route::post('/suppliers/{supplier}/products', [SupplierController::class, 'addProduct'])->name('suppliers.products.store');
            Route::delete('/suppliers/{supplier}/products/{supplierProduct}', [SupplierController::class, 'removeProduct'])->name('suppliers.products.destroy');
            Route::apiResource('purchase-orders', PurchaseOrderController::class);
            Route::post('/purchase-orders/{purchaseOrder}/pay', [PurchaseOrderController::class, 'pay'])->name('purchase-orders.pay');
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
            Route::post('/goods-receipts/{goodsReceipt}/pay', [GoodsReceiptController::class, 'pay'])->name('goods-receipts.pay');
        });

        // ─── Inventory ───────────────────────────────────────────────
        Route::middleware('permission:inventory')->group(function () {
            Route::apiResource('warehouses', WarehouseController::class);
            Route::post('/stock-movements/transfer', [StockMovementController::class, 'transfer'])->name('stock-movements.transfer');
            Route::apiResource('stock-movements', StockMovementController::class)->except(['update', 'destroy']);

            Route::post('/product-batches/sell-fefo', [ProductBatchController::class, 'sellFefo'])->name('product-batches.sellFefo');
            Route::post('/product-batches/grn-receive', [ProductBatchController::class, 'grnReceive'])->name('product-batches.grnReceive');
            Route::post('/product-batches/{productBatch}/sell', [ProductBatchController::class, 'sell'])->name('product-batches.sell');
            Route::post('/product-batches/{productBatch}/adjust', [ProductBatchController::class, 'adjust'])->name('product-batches.adjust');
            Route::apiResource('product-batches', ProductBatchController::class);

            Route::get('/inventory-adjustments/expiry-alerts', [InventoryAdjustmentController::class, 'expiryAlerts'])->name('inventory-adjustments.expiryAlerts');
            Route::get('/inventory-adjustments/low-stock', [InventoryAdjustmentController::class, 'lowStock'])->name('inventory-adjustments.lowStock');
            Route::post('/inventory-adjustments/auto-waste', [InventoryAdjustmentController::class, 'autoWaste'])->name('inventory-adjustments.autoWaste');
            Route::apiResource('inventory-adjustments', InventoryAdjustmentController::class)->except(['edit', 'create', 'update']);

            Route::post('/product-variants/generate', [ProductVariantController::class, 'generate'])->name('product-variants.generate');
            Route::apiResource('product-variants', ProductVariantController::class);

            Route::apiResource('collections', CollectionController::class);

            Route::post('/serial-numbers/sell/{serialNumber}', [SerialNumberController::class, 'sell']);
            Route::apiResource('serial-numbers', SerialNumberController::class);

            Route::patch('/warranty-claims/{warrantyClaim}/status', [WarrantyClaimController::class, 'updateStatus']);
            Route::apiResource('warranty-claims', WarrantyClaimController::class);

            Route::patch('/service-tickets/{serviceTicket}/status', [ServiceTicketController::class, 'updateStatus']);
            Route::apiResource('service-tickets', ServiceTicketController::class);
        });

        // ─── Accounting (Admin / Accountant only) ─────────────────
        Route::middleware('permission:accounting')->group(function () {
            Route::get('accounts/next-code', [AccountController::class, 'nextCode'])->name('accounts.next-code');
            Route::apiResource('accounts', AccountController::class);
            Route::apiResource('journal-entries', JournalEntryController::class);
            Route::post('/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->name('journal-entries.post');
            Route::post('/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');

            // ─── AR / AP Subledgers ─────────────────────────────────
            Route::get('/accounting/ar', [AccountingController::class, 'receivables'])->name('accounting.receivables');
            Route::get('/accounting/ap', [AccountingController::class, 'payables'])->name('accounting.payables');
            Route::get('/accounting/ar/{customer}/statement', [CustomerController::class, 'statement'])->name('accounting.receivableStatement');
            Route::get('/accounting/ap/{supplier}/statement', [AccountingController::class, 'payableStatement'])->name('accounting.payableStatement');
            Route::post('/accounting/ar/{invoice}/pay', [InvoiceController::class, 'pay'])->name('accounting.ar.pay');
            Route::post('/accounting/ap/{purchaseOrder}/pay', [PurchaseOrderController::class, 'pay'])->name('accounting.ap.pay');
            Route::post('/accounting/ap/receipts/{goodsReceipt}/pay', [GoodsReceiptController::class, 'pay'])->name('accounting.ap.receipt.pay');

            // ─── Fiscal Years ───────────────────────────────────────
            Route::apiResource('fiscal-years', FiscalYearController::class);
            Route::post('/fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->name('fiscal-years.close');

            // ─── Bank Reconciliation ────────────────────────────────
            Route::apiResource('bank-reconciliations', BankReconciliationController::class);
            Route::post('/bank-reconciliations/{bankReconciliation}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliations.autoMatch');
            Route::post('/bank-reconciliations/{bankReconciliation}/close', [BankReconciliationController::class, 'close'])->name('bank-reconciliations.close');
            Route::post('/bank-reconciliations/{bankReconciliation}/lines', [BankReconciliationController::class, 'addLine'])->name('bank-reconciliations.addLine');
            Route::post('/bank-reconciliations/{bankReconciliation}/lines/{line}/reconcile', [BankReconciliationController::class, 'reconcileLine'])->name('bank-reconciliations.reconcileLine');
            Route::post('/bank-reconciliations/{bankReconciliation}/lines/{line}/unreconcile', [BankReconciliationController::class, 'unreconcileLine'])->name('bank-reconciliations.unreconcileLine');
            Route::post('/bank-reconciliations/{bankReconciliation}/import', [BankReconciliationController::class, 'importStatement'])->name('bank-reconciliations.import');
        });

        // ─── Reports ────────────────────────────────────────────────
        Route::middleware('permission:reports')->group(function () {
            Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trialBalance');
            Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profitAndLoss');
            Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balanceSheet');
            Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cashFlow');
            Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.generalLedger');
            Route::get('/reports/sales-summary', [ReportController::class, 'salesSummary'])->name('reports.salesSummary');
            Route::get('/reports/stock-valuation', [ReportController::class, 'stockValuation'])->name('reports.stockValuation');
            Route::get('/reports/supplier-aging', [ReportController::class, 'supplierAging'])->name('reports.supplierAging');
        });

        // ─── Promotions CRUD ────────────────────────────────────────
        Route::get('/promotions', [PromotionController::class, 'index'])->middleware('permission:sales.view');
        Route::get('/promotions/{promotion}', [PromotionController::class, 'show'])->middleware('permission:sales.view');
        Route::post('/promotions', [PromotionController::class, 'store'])->middleware('permission:sales.create');
        Route::match(['put', 'patch'], '/promotions/{promotion}', [PromotionController::class, 'update'])->middleware('permission:sales.edit');
        Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->middleware('permission:sales.delete');

        // ─── Restaurant ───────────────────────────────────────────
        Route::prefix('restaurant')->as('restaurant.')->group(function () {
            Route::apiResource('tables', RestaurantTableController::class)->except(['edit', 'create']);
            Route::patch('/tables/{restaurantTable}/status', [RestaurantTableController::class, 'updateStatus'])->name('tables.updateStatus');

            Route::apiResource('reservations', ReservationController::class)->except(['edit', 'create']);
            Route::patch('/reservations/{reservation}/status', [ReservationController::class, 'updateStatus'])->name('reservations.updateStatus');

            Route::apiResource('recipes', RecipeController::class)->except(['edit', 'create']);

            Route::apiResource('kitchen-orders', KitchenOrderController::class)->except(['edit', 'create']);
            Route::patch('/kitchen-orders/{kitchenOrder}/status', [KitchenOrderController::class, 'updateStatus'])->name('kitchen-orders.updateStatus');
        });

        // ─── Clinic ───────────────────────────────────────────────
        Route::prefix('clinic')->as('clinic.')->group(function () {
            Route::apiResource('appointments', AppointmentController::class)->except(['edit', 'create']);
            Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.updateStatus');

            Route::get('/dental-charts/patient/{customerId}', [DentalChartController::class, 'patientChart'])->name('dental-charts.patient');
            Route::post('/dental-charts/bulk', [DentalChartController::class, 'bulkStore'])->name('dental-charts.bulk');
            Route::apiResource('dental-charts', DentalChartController::class)->except(['edit', 'create']);

            Route::apiResource('treatment-plans', TreatmentPlanController::class)->except(['edit', 'create']);

            Route::patch('/treatment-procedures/{treatmentProcedure}/status', [TreatmentProcedureController::class, 'updateStatus'])->name('treatment-procedures.updateStatus');
            Route::apiResource('treatment-procedures', TreatmentProcedureController::class)->except(['edit', 'create']);

            Route::apiResource('medical-records', MedicalRecordController::class)->except(['edit', 'create']);
            Route::apiResource('prescriptions', PrescriptionController::class)->except(['edit', 'create']);

            Route::patch('/lab-orders/{labOrder}/results', [LabOrderController::class, 'updateResults'])->name('lab-orders.updateResults');
            Route::apiResource('lab-orders', LabOrderController::class)->except(['edit', 'create']);

            Route::patch('/insurance-claims/{insuranceClaim}/status', [InsuranceClaimController::class, 'updateStatus'])->name('insurance-claims.updateStatus');
            Route::apiResource('insurance-claims', InsuranceClaimController::class)->except(['edit', 'create']);
        });

        // ─── Jewelry ─────────────────────────────────────────────
        Route::prefix('jewelry')->as('jewelry.')->group(function () {
            Route::get('/gold-rate', GoldRateController::class)->name('gold-rate');
            Route::get('/gold-rate/latest', [GoldRateLogController::class, 'latest'])->name('gold-rate.latest');
            Route::apiResource('gold-rate-logs', GoldRateLogController::class)->except(['edit', 'create']);

            Route::patch('/repair-tickets/{repairTicket}/status', [RepairTicketController::class, 'updateStatus'])->name('repair-tickets.updateStatus');
            Route::apiResource('repair-tickets', RepairTicketController::class)->except(['edit', 'create']);

            Route::apiResource('police-book-entries', PoliceBookEntryController::class)->except(['edit', 'create']);
        });
    });
});
