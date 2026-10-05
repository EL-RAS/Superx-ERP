<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single place that decides which alerts exist and who receives them.
 *
 * Delivery rules
 * --------------
 * - Recipients are every active user of the business: notifications are
 *   store-level, and each user owns their own read state and badge.
 * - Every alert carries a subject-unique `tag` (`stock_low:product:5`). While an
 *   unread notification with that tag exists nothing is re-sent, so a product
 *   sold in a hundred small transactions produces one badge, not a hundred.
 *   Reading the alert re-arms it, which is what lets a genuinely recurring
 *   problem notify again later.
 * - `sweep()` re-evaluates the *current* stock/expiry position when the user
 *   opens their notifications. That makes alerts self-healing without a cron
 *   job: the repository has no scheduler registered, so condition-drift (a
 *   batch creeping into its warning window with nobody saving it) would
 *   otherwise never be noticed.
 */
class NotificationService
{
    /** @return Collection<int, User> */
    public function recipients(string $businessId): Collection
    {
        return User::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->get();
    }

    public function forBusiness(string $businessId, AppNotification $notification, bool $once = false): void
    {
        foreach ($this->recipients($businessId) as $user) {
            $this->deliver($user, $notification, $once);
        }
    }

    public function welcome(User $user): void
    {
        if (! $user->business_id) {
            return;
        }

        $this->deliver($user, new AppNotification(
            tag: 'welcome',
            severity: 'info',
            title: ['en' => 'Welcome to SuperX', 'ar' => 'مرحباً بك في SuperX'],
            message: [
                'en' => sprintf('Hello %s, your workspace is ready. Start by adding your first products.', $user->name),
                'ar' => sprintf('أهلاً %s، مساحة عملك جاهزة. ابدأ بإضافة منتجاتك الأولى.', $user->name),
            ],
            actionUrl: '/dashboard',
        ), once: true);
    }

    public function checkStock(Product $product): void
    {
        $businessId = (string) $product->business_id;

        // min_stock is the merchant's opt-in to stock thresholds; 0 means the
        // product is not reorder-tracked (same rule as the low-stock report).
        $min = (float) $product->min_stock;
        if ($min <= 0) {
            return;
        }

        $stock = (float) $product->stock_quantity;
        $name = $product->name;

        if ($stock <= 0) {
            $this->forBusiness($businessId, new AppNotification(
                tag: "stock_out:product:{$product->id}",
                severity: 'danger',
                title: ['en' => 'Out of stock', 'ar' => 'نفد المخزون'],
                message: [
                    'en' => sprintf('%s is out of stock (reorder level: %s).', $name, $this->num($min)),
                    'ar' => sprintf('نفد مخزون %s (حد إعادة الطلب: %s).', $name, $this->num($min)),
                ],
                actionUrl: '/products',
            ));

            return;
        }

        if ($stock <= $min) {
            $this->forBusiness($businessId, new AppNotification(
                tag: "stock_low:product:{$product->id}",
                severity: 'warning',
                title: ['en' => 'Low stock alert', 'ar' => 'تنبيه مخزون منخفض'],
                message: [
                    'en' => sprintf('%s has %s %s left (reorder level: %s).', $name, $this->num($stock), $product->unit, $this->num($min)),
                    'ar' => sprintf('تبقى %s %s من %s (حد إعادة الطلب: %s).', $this->num($stock), $product->unit, $name, $this->num($min)),
                ],
                actionUrl: '/products',
            ));
        }
    }

    public function checkExpiry(ProductBatch $batch): void
    {
        if (! $batch->expiry_date || ! $batch->business_id) {
            return;
        }

        $remaining = (float) $batch->quantity - (float) $batch->quantity_sold;
        if ($remaining <= 0) {
            return;
        }

        $warningDays = $this->expiryWarningDays($batch->business_id);
        $today = now()->startOfDay();
        $expiry = Carbon::parse($batch->expiry_date)->startOfDay();

        // Signed day delta (negative = already expired). Computed from
        // timestamps so it does not depend on Carbon's diffInDays sign flip.
        $days = (int) floor(($expiry->getTimestamp() - $today->getTimestamp()) / 86400);

        if ($days < 0) {
            $this->forBusiness((string) $batch->business_id, new AppNotification(
                tag: "expiry_passed:batch:{$batch->id}",
                severity: 'danger',
                title: ['en' => 'Batch expired', 'ar' => 'دفعة منتهية الصلاحية'],
                message: [
                    'en' => sprintf('Batch %s expired %d day(s) ago with %s remaining.', $batch->batch_number, abs($days), $this->num($remaining)),
                    'ar' => sprintf('انتهت صلاحية الدفعة %s منذ %d يوم مع بقاء %s.', $batch->batch_number, abs($days), $this->num($remaining)),
                ],
                actionUrl: '/inventory/batches',
            ));

            return;
        }

        if ($days <= $warningDays) {
            $this->forBusiness((string) $batch->business_id, new AppNotification(
                tag: "expiry_soon:batch:{$batch->id}",
                severity: 'warning',
                title: ['en' => 'Expiring soon', 'ar' => 'قرب انتهاء الصلاحية'],
                message: [
                    'en' => sprintf('Batch %s expires in %d day(s) with %s remaining.', $batch->batch_number, $days, $this->num($remaining)),
                    'ar' => sprintf('تنتهي صلاحية الدفعة %s خلال %d يوم مع بقاء %s.', $batch->batch_number, $days, $this->num($remaining)),
                ],
                actionUrl: '/inventory/expiry',
            ));
        }
    }

    public function purchaseOrderPlaced(PurchaseOrder $po): void
    {
        if (! $po->business_id) {
            return;
        }

        $supplier = $po->supplier?->name;
        $due = $po->expected_delivery
            ? Carbon::parse($po->expected_delivery)->toFormattedDateString()
            : null;

        $this->forBusiness((string) $po->business_id, new AppNotification(
            tag: "po:{$po->id}",
            severity: 'info',
            title: ['en' => 'Purchase order placed', 'ar' => 'تم إنشاء أمر شراء'],
            message: [
                'en' => trim(sprintf(
                    '%s%s — %s%s.',
                    $po->order_number,
                    $supplier !== null ? " to {$supplier}" : '',
                    $this->num((float) $po->total_amount),
                    $due !== null ? ", due {$due}" : ''
                )),
                'ar' => trim(sprintf(
                    '%s%s — بمبلغ %s%s.',
                    $po->order_number,
                    $supplier !== null ? ' لدى '.$supplier : '',
                    $this->num((float) $po->total_amount),
                    $due !== null ? '، يستحق في '.$due : ''
                )),
            ],
            actionUrl: '/purchase-orders',
        ));
    }

    public function invoiceRequiresAction(Invoice $invoice): void
    {
        if (! $invoice->business_id) {
            return;
        }

        // Only genuinely open documents are actionable; paid/void ones are not.
        // `payment_status` runs unpaid/partial/paid while `status` carries void.
        if ($invoice->payment_status === 'paid' || $invoice->status === 'void') {
            return;
        }

        $partial = $invoice->payment_status === 'partial';

        $this->forBusiness((string) $invoice->business_id, new AppNotification(
            tag: "invoice:{$invoice->id}",
            severity: 'warning',
            title: ['en' => 'Invoice requires action', 'ar' => 'فاتورة بانتظار الإجراء'],
            message: [
                'en' => sprintf(
                    '%s is %s with %s outstanding.',
                    $invoice->invoice_number,
                    $partial ? 'partly paid' : 'awaiting payment',
                    $this->num((float) $invoice->net_amount)
                ),
                'ar' => sprintf(
                    'الفاتورة %s %s بمبلغ مستحق %s.',
                    $invoice->invoice_number,
                    $partial ? 'مدفوعة جزئياً' : 'بانتظار الدفع',
                    $this->num((float) $invoice->net_amount)
                ),
            ],
            actionUrl: '/invoices',
        ));
    }

    /**
     * Re-evaluate everything that could have drifted into an alert state since
     * the last read. Bounded: both queries only select rows that currently
     * qualify, so the scan stays a single indexed pass over the business.
     */
    public function sweep(string $businessId): void
    {
        Product::query()
            ->where('business_id', $businessId)
            ->where('min_stock', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'min_stock')
            ->get(['id', 'business_id', 'name', 'unit', 'min_stock', 'stock_quantity'])
            ->each(fn (Product $product) => $this->checkStock($product));

        $warningDays = $this->expiryWarningDays($businessId);

        ProductBatch::query()
            ->where('business_id', $businessId)
            ->whereNotNull('expiry_date')
            ->whereRaw('(quantity - quantity_sold) > 0')
            ->where('expiry_date', '<=', now()->addDays($warningDays))
            ->get()
            ->each(fn (ProductBatch $batch) => $this->checkExpiry($batch));
    }

    public function expiryWarningDays(string $businessId): int
    {
        $business = Business::query()->find($businessId);
        $days = $business?->mergedSettings()['expiry_warning_days'] ?? 30;

        return max(1, min(365, (int) $days));
    }

    private function deliver(User $user, AppNotification $notification, bool $once): void
    {
        if ($this->alreadyNotified($user, $notification->tag, ! $once)) {
            return;
        }

        $user->notify($notification);
    }

    private function alreadyNotified(User $user, string $tag, bool $onlyUnread): bool
    {
        $query = $user->notifications()->where('data->tag', $tag);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        return $query->exists();
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
