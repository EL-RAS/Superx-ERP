<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessType extends Model
{
    protected $fillable = [
        'slug',
        'name_en',
        'name_ar',
        'allowed_modules',
        'default_settings',
    ];

    protected function casts(): array
    {
        return [
            'allowed_modules' => 'array',
            'default_settings' => 'array',
        ];
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}
