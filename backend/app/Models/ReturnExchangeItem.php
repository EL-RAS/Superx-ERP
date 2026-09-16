<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnExchangeItem extends Model
{
    protected $fillable = [
        'return_exchange_id',
        'invoice_item_id',
        'product_id',
        'batch_id',
        'quantity',
        'unit_price',
        'tax_rate',
        'reason',
        'is_exchange',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_exchange' => 'boolean',
        ];
    }

    public function returnExchange(): BelongsTo
    {
        return $this->belongsTo(ReturnExchange::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
