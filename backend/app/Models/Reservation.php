<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'customer_id',
        'table_id',
        'guest_name',
        'guest_phone',
        'party_size',
        'reservation_date',
        'reservation_time',
        'duration_minutes',
        'status',
        'occasion',
        'special_requests',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'reservation_time' => 'string',
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

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
