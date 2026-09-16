<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairTicket extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'customer_id',
        'ticket_number',
        'item_description',
        'item_weight',
        'item_carat',
        'issue_description',
        'estimated_cost',
        'actual_cost',
        'status',
        'received_at',
        'quoted_at',
        'completed_at',
        'delivered_at',
        'technician',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'item_weight' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'received_at' => 'datetime',
            'quoted_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
