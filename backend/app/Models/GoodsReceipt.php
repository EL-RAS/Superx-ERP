<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceipt extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'supplier_id',
        'user_id',
        'receipt_number',
        'reference_invoice_number',
        'notes',
        'received_at',
        'payment_method',
        'total_amount',
        'pay_now_amount',
        'status',
    ];

    protected $appends = ['payable_credit'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'pay_now_amount' => 'decimal:2',
        ];
    }

    /**
     * The portion of this receipt recognised on Account 2010 (payable).
     * Cash/bank recorded at the door (pay_now_amount) is posted straight to
     * the cash/bank account, so only the remainder becomes a payable.
     */
    public function getPayableCreditAttribute(): float
    {
        return round((float) ($this->total_amount ?? 0) - (float) ($this->pay_now_amount ?? 0), 2);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function payments()
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }
}
