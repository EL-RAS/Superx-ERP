<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RbacService
{
    public static function modules(): array
    {
        return config('rbac.modules', []);
    }

    public static function actions(): array
    {
        return config('rbac.actions', ['view', 'create', 'edit', 'delete']);
    }

    public static function systemRoles(): array
    {
        return config('rbac.system_roles', []);
    }

    /**
     * Special permissions granted automatically to the admin role but not
     * rendered in the roles matrix grid. `sales.lock` gates editing/voiding
     * existing invoices (strict invoice lock). `pos.shifts` gates shift
     * management (history + z-reports), which is admin-only by default.
     */
    public const SPECIAL_KEYS = ['sales.lock', 'pos.shifts'];

    public static function allPermissionKeys(): array
    {
        $keys = [];

        foreach (array_keys(self::modules()) as $module) {
            foreach (self::actions() as $action) {
                $keys[] = $module.'.'.$action;
            }
        }

        return array_merge($keys, self::SPECIAL_KEYS);
    }

    public static function isValidKey(string $key): bool
    {
        [$module, $action] = array_pad(explode('.', $key, 2), 2, null);

        if (in_array($key, self::SPECIAL_KEYS, true)) {
            return true;
        }

        return $module !== null
            && $action !== null
            && isset(self::modules()[$module])
            && in_array($action, self::actions(), true);
    }

    public static function defaultPermissionsFor(string $roleSlug): array
    {
        $defaults = config("rbac.defaults.{$roleSlug}", []);

        if ($defaults === '*') {
            return self::allPermissionKeys();
        }

        $keys = [];

        foreach ($defaults as $module => $actions) {
            foreach ($actions as $action) {
                $keys[] = $module.'.'.$action;
            }
        }

        return $keys;
    }

    /**
     * Idempotently seed the system roles (and their default permission matrix)
     * for a business. Uses DB::table so it also runs safely inside migrations.
     */
    public static function seedForBusiness(string $businessId): void
    {
        $existing = DB::table('roles')
            ->where('business_id', $businessId)
            ->pluck('slug')
            ->all();

        $now = now();

        foreach (self::systemRoles() as $slug => $name) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $businessId,
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'permissions' => json_encode(self::defaultPermissionsFor($slug)),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
