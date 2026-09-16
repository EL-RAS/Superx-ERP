<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_type_id',
        'parent_id',
        'name',
        'name_ar',
        'color',
        'sort_order',
    ];

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * The category's own id plus every descendant category id (recursive).
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $queue = $this->children;

        while ($queue->isNotEmpty()) {
            $ids = array_merge($ids, $queue->pluck('id')->all());
            $queue = $queue->flatMap(fn (self $child) => $child->children);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
