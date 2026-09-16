<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public const DEFAULTS = [
        'sales_invoice_prefix' => 'INV-',
        'purchase_order_prefix' => 'PO-',
        'grn_prefix' => 'GRN-',
    ];

    /**
     * Run a document-creation closure, retrying from scratch when the insert
     * collides on the business-scoped unique document number. The counter is
     * bumped by the winning transaction, so the retry always re-allocates a
     * fresh number via nextFor(). This makes concurrent allocations correct
     * regardless of lock timing or an unpopulated counter table.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  string  $table  invoices | purchase_orders | goods_receipts
     * @return T
     */
    public static function retryOnConflict(callable $callback, string $table, int $maxAttempts = 3): mixed
    {
        $constraint = static::constraintFor($table);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $e) {
                if ($attempt >= $maxAttempts || ! static::isConstraintViolation($e, $constraint)) {
                    throw $e;
                }

                usleep(50_000 * $attempt);
            }
        }
    }

    private static function constraintFor(string $table): ?string
    {
        return match ($table) {
            'invoices' => 'invoices_business_id_invoice_number_unique',
            'purchase_orders' => 'purchase_orders_business_id_order_number_unique',
            'goods_receipts' => 'goods_receipts_business_id_receipt_number_unique',
            default => null,
        };
    }

    private static function isConstraintViolation(QueryException $e, ?string $constraint): bool
    {
        if ($constraint === null || ($e->errorInfo[0] ?? null) !== '23505') {
            return false;
        }

        return str_contains($e->getMessage(), $constraint);
    }

    /**
     * Allocate the next sequential document number for a (business, kind).
     *
     * Serialization: a per-(business, kind, prefix) row in `document_sequences`
     * is row-locked with SELECT ... FOR UPDATE inside the caller's transaction.
     * The lock is held until that transaction commits, so two concurrent
     * allocations can never compute the same number. The advisory xact lock is
     * kept as a second line of defence (released at the same commit).
     */
    public static function nextFor(array $settings, string $kind, string $businessId): string
    {
        $prefix = $settings[static::prefixKey($kind)] ?? static::DEFAULTS[static::prefixKey($kind)];

        // Callers allocate inside their own DB::transaction; nesting here means
        // a savepoint, and PostgreSQL row locks survive savepoint release, so
        // the lock stays held until the caller's outer transaction commits.
        return DB::transaction(function () use ($prefix, $kind, $businessId) {
            static::lock($kind, $businessId);
            static::ensureCounter($prefix, $kind, $businessId);

            $current = (int) DB::table('document_sequences')
                ->where('business_id', $businessId)
                ->where('kind', $kind)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->value('current');

            $next = static::nextInteger($prefix, static::table($kind), static::column($kind), $businessId, $current);

            DB::table('document_sequences')
                ->where('business_id', $businessId)
                ->where('kind', $kind)
                ->where('prefix', $prefix)
                ->update(['current' => $next, 'updated_at' => now()]);

            return $prefix.$next;
        });
    }

    /**
     * Read-only preview of the next number (used by the settings screen).
     * Never increments the counter and never locks, so it cannot interfere
     * with real allocations.
     */
    public static function peek(array $settings, string $kind, string $businessId): string
    {
        $prefix = $settings[static::prefixKey($kind)] ?? static::DEFAULTS[static::prefixKey($kind)];

        $counter = (int) DB::table('document_sequences')
            ->where('business_id', $businessId)
            ->where('kind', $kind)
            ->where('prefix', $prefix)
            ->value('current') ?? 0;

        $next = static::nextInteger($prefix, static::table($kind), static::column($kind), $businessId, $counter);

        return $prefix.$next;
    }

    private static function lock(string $kind, string $businessId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['docseq:'.$kind.':'.$businessId]);
        }
    }

    private static function ensureCounter(string $prefix, string $kind, string $businessId): void
    {
        DB::table('document_sequences')->insertOrIgnore([
            'business_id' => $businessId,
            'kind' => $kind,
            'prefix' => $prefix,
            'current' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function nextInteger(string $prefix, string $table, string $column, string $businessId, int $floor): int
    {
        $pattern = '^'.preg_quote($prefix, '/').'([0-9]+)$';

        $max = (int) DB::table($table)
            ->where('business_id', $businessId)
            ->whereRaw("{$column} ~ ?", [$pattern])
            ->selectRaw("COALESCE(MAX((regexp_match({$column}, ?))[1])::int, 0)", [$pattern])
            ->value('max') ?? 0;

        return max($floor, $max) + 1;
    }

    private static function prefixKey(string $kind): string
    {
        return match ($kind) {
            'invoice' => 'sales_invoice_prefix',
            'purchase_order' => 'purchase_order_prefix',
            'grn' => 'grn_prefix',
        };
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'invoice' => 'invoices',
            'purchase_order' => 'purchase_orders',
            'grn' => 'goods_receipts',
        };
    }

    private static function column(string $kind): string
    {
        return match ($kind) {
            'invoice' => 'invoice_number',
            'purchase_order' => 'order_number',
            'grn' => 'receipt_number',
        };
    }
}
