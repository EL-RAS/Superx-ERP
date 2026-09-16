<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'name',
        'contact_name',
        'email',
        'phone',
        'tax_number',
        'address',
        'payment_terms',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function purchasePayments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /**
     * Every catalog item this supplier can provide (imported or not yet
     * present in the system inventory).
     */
    public function suppliedProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /**
     * Catalog items that are already linked to a real product in the system
     * inventory (i.e. currently imported/sourced from this supplier).
     */
    public function importedProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class)->whereNotNull('product_id');
    }
}
