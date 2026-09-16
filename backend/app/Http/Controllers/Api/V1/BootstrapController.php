<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BusinessContext;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    public function __construct(
        protected BusinessContext $businessContext,
    ) {}

    public function __invoke(): JsonResponse
    {
        $business = $this->businessContext->business();
        $businessType = $business->businessType;
        $slug = $businessType->slug;
        $modules = $businessType->allowed_modules;
        $user = auth()->user();
        $permissions = $user?->permissionKeys() ?? [];
        $can = fn (string $key) => in_array($key, $permissions, true);
        $settings = $business->mergedSettings();
        unset($settings['jofotara_secret_key']);

        return response()->json([
            'business_id' => $business->id,
            'business_name' => $business->name,
            'business_slug' => $business->slug,
            'business_type' => $slug,
            'display_name' => $business->name,
            'logo' => $business->logo,
            'currency' => 'JOD',
            'locale' => 'ar',
            'rtl' => true,
            'modules' => $modules,
            'settings' => $settings,
            'user_permissions' => $permissions,
            'subscription' => $business->subscriptionPayload(),
            'navigation' => $this->coreNavigation($slug, $can, $settings),
            'features' => [
                'pos_enabled' => true,
                'barcode_scanner' => (bool) ($settings['barcode_scanner'] ?? true),
                'rapid_mode' => (bool) ($settings['rapid_mode'] ?? false),
                'batch_tracking' => true,
                'expiry_alerts' => (bool) ($settings['expiry_alerts'] ?? false),
                'crm_enabled' => (bool) config('features.crm_enabled'),
                'loyalty_enabled' => (bool) config('features.crm_enabled') && (bool) ($settings['loyalty_enabled'] ?? false),
                'promotions_enabled' => (bool) ($settings['promotions_enabled'] ?? false),
                'low_stock_sensitivity' => $settings['low_stock_sensitivity'] ?? 'normal',
            ],
            'product_table_columns' => $this->defaultProductColumns(),
            'theme' => ['primary_color' => '#3B82F6', 'sidebar_style' => 'compact'],
            'dashboard_widgets' => ['stats_cards', 'recent_invoices'],
        ]);
    }

    private function coreNavigation(string $slug, callable $can, array $settings = []): array
    {
        $showVariants = ! in_array($slug, ['supermarket_hypermarket', 'grocery', 'pharmacy', 'restaurant'], true);

        $inventoryChildren = [
            ['label' => 'Products', 'route' => '/products', 'icon' => 'package'],
            ['label' => 'Batches', 'route' => '/inventory/batches', 'icon' => 'layers'],
        ];

        if ($showVariants) {
            $inventoryChildren[] = ['label' => 'Variants', 'route' => '/inventory/variants', 'icon' => 'grid-3x3'];
        }

        $inventoryChildren = array_merge($inventoryChildren, [
            ['label' => 'Adjustments', 'route' => '/inventory/adjustments', 'icon' => 'sliders'],
            ['label' => 'Low Stock', 'route' => '/inventory/low-stock', 'icon' => 'alert-triangle'],
            ['label' => 'Warehouses', 'route' => '/warehouses', 'icon' => 'building-2'],
        ]);

        $navigation = [];

        if ($can('dashboard.view')) {
            $navigation[] = $this->navItem('Dashboard', '/dashboard', 'layout-dashboard');
        }

        if ($can('pos.view')) {
            $posChildren = [
                ['label' => 'POS', 'route' => '/pos', 'icon' => 'shopping-cart'],
            ];

            if ($can('pos.shifts')) {
                $posChildren[] = ['label' => 'Shifts', 'route' => '/pos/shifts', 'icon' => 'clock'];
            }

            $navigation[] = $this->navItemWithChildren('POS', 'shopping-cart', $posChildren);
        }

        if ($can('sales.view')) {
            $salesChildren = [
                ['label' => 'Invoices', 'route' => '/invoices', 'icon' => 'file-text'],
                ['label' => 'Orders', 'route' => '/orders', 'icon' => 'credit-card'],
            ];

            if (! empty($settings['promotions_enabled'])) {
                $salesChildren[] = ['label' => 'Promotions', 'route' => '/promotions', 'icon' => 'percent'];
            }

            if ($slug === 'clothing_apparel') {
                $salesChildren[] = ['label' => 'Returns & Exchanges', 'route' => '/returns-exchanges', 'icon' => 'repeat'];
            }

            $navigation[] = $this->navItemWithChildren('Sales', 'receipt', $salesChildren);
        }

        if ($can('inventory.view')) {
            $navigation[] = $this->navItemWithChildren('Inventory', 'package', $inventoryChildren);
        }

        if ($can('accounting.view')) {
            $navigation[] = $this->navItemWithChildren('Accounting', 'calculator', [
                ['label' => 'Accounts', 'route' => '/accounts', 'icon' => 'book-open'],
                ['label' => 'Journal Entries', 'route' => '/journal-entries', 'icon' => 'file-text'],
                ['label' => 'Accounts Receivable', 'route' => '/accounting/ar', 'icon' => 'trending-up'],
                ['label' => 'Accounts Payable', 'route' => '/accounting/ap', 'icon' => 'trending-down'],
            ]);
        }

        if ($can('crm.view') && (bool) config('features.crm_enabled')) {
            $crmChildren = [
                ['label' => 'Customers', 'route' => '/crm/customers', 'icon' => 'user'],
                ['label' => 'Campaigns', 'route' => '/crm/campaigns', 'icon' => 'mail'],
            ];

            $navigation[] = $this->navItemWithChildren('CRM', 'users', $crmChildren);
        }

        if ($can('users_roles.view')) {
            $navigation[] = $this->navItemWithChildren('Users & Roles', 'shield', [
                ['label' => 'Users', 'route' => '/users', 'icon' => 'user'],
                ['label' => 'Roles', 'route' => '/roles', 'icon' => 'key'],
            ]);
        }

        if ($can('purchases.view')) {
            $navigation[] = $this->navItemWithChildren('Purchases', 'truck', [
                ['label' => 'Suppliers', 'route' => '/suppliers', 'icon' => 'users'],
                ['label' => 'Purchase Orders', 'route' => '/purchase-orders', 'icon' => 'clipboard-list'],
                ['label' => 'Goods Receipt', 'route' => '/purchases/grn', 'icon' => 'package-check'],
            ]);
        }

        if ($can('reports.view')) {
            $navigation[] = $this->navItem('Reports', '/reports', 'bar-chart-2');
        }

        if ($can('settings.view')) {
            $navigation[] = $this->navItem('Settings', '/settings', 'settings');
        }

        return $navigation;
    }

    private function navItem(string $label, string $route, string $icon, ?string $badge = null): array
    {
        $item = [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
        ];
        if ($badge !== null) {
            $item['badge'] = $badge;
        }

        return $item;
    }

    private function navItemWithChildren(string $label, string $icon, array $children): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'children' => $children,
        ];
    }

    private function column(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private function defaultProductColumns(): array
    {
        return [
            $this->column('name', 'Product'),
            $this->column('sku', 'SKU'),
            $this->column('price', 'Price', 'currency'),
            $this->column('cost', 'Cost', 'currency'),
            $this->column('is_active', 'Status', 'boolean'),
        ];
    }
}
