<?php

namespace App\Models;

use App\Services\RbacService;
use App\Traits\BelongsToBusiness;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['business_id', 'name', 'email', 'username', 'password', 'role', 'is_active', 'is_primary_admin', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, BelongsToBusiness;

    /** @use HasFactory<UserFactory> */
    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_primary_admin' => 'boolean',
        ];
    }

    public function isPrimaryAdmin(): bool
    {
        return (bool) $this->is_primary_admin;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Every permission key granted to this user through their role.
     * Admin bypasses the lookup and receives the full system matrix.
     */
    public function permissionKeys(): array
    {
        if ($this->role === 'admin') {
            return RbacService::allPermissionKeys();
        }

        $role = Role::withoutBusiness()
            ->where('business_id', $this->business_id)
            ->where('slug', $this->role)
            ->first();

        return $role?->permissions ?? [];
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function hasAnyPermission(array $keys): bool
    {
        return count(array_intersect($keys, $this->permissionKeys())) > 0;
    }
}
