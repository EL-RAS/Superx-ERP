<?php

namespace App\Jobs;

use App\Services\InventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background self-healing for the 1030 Inventory Asset account.
 *
 * Runs InventorySyncService::sync() for a business in the background after
 * import / GRN / manual batch flows have committed, closing any drift between
 * the ledger balance and the real on-hand valuation with an automatic
 * adjusting entry (Dr/Cr 1030 vs 3010). It is a safety net: the inline posts
 * (postImportOpeningStockEntry, postGoodsReceiptEntry) already keep parity,
 * this catches anything that escaped them. The delta math in sync() makes it
 * a no-op whenever the ledger is already in sync, so re-running is always safe.
 */
class ReconcileInventoryAccounting implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly ?int $userId = null,
    ) {}

    public function handle(InventorySyncService $sync): void
    {
        try {
            $result = $sync->sync($this->businessId, $this->userId);

            if (! empty($result['changed']) && ! empty($result['entry_id'])) {
                Log::info('Background inventory reconciliation posted an adjusting entry.', [
                    'business_id' => $this->businessId,
                    'entry_id' => $result['entry_id'],
                    'delta' => $result['delta'] ?? null,
                    'stock_value' => $result['stock_value'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Reconciliation must never take down the flow that queued it.
            Log::warning('Background inventory reconciliation failed.', [
                'business_id' => $this->businessId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
