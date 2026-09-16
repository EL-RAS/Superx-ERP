<?php

namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SerialNumber extends Model
{
    use SoftDeletes;
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();

        static::creating(function ($model) {
            if (empty($model->status)) {
                $model->status = 'in_stock';
            }
            if (empty($model->warranty_status)) {
                $model->warranty_status = 'active';
            }
        });
    }

    protected $fillable = [
        'business_id',
        'product_id',
        'serial_number',
        'imei',
        'warranty_start',
        'warranty_end',
        'warranty_status',
        'status',
        'customer_id',
        'sold_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'warranty_start' => 'date',
            'warranty_end' => 'date',
            'sold_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }
}
