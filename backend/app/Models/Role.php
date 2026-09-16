<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'business_id', 'name', 'slug', 'description', 'permissions', 'is_system'])]
#[Hidden(['business_id'])]
class Role extends Model
{
    use BelongsToBusiness;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissions ?? [], true);
    }
}
