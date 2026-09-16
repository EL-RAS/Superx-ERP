<?php

namespace Database\Seeders;

use App\Models\User;
use App\Scopes\BusinessScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions the platform-owner account used to access the SuperX
 * Super Admin / tenant-management portal.
 *
 * Access to the portal requires an authenticated `users` row whose
 * `role` is `superx_owner` with `business_id = null` (see
 * EnsurePlatformOwner + AuthController). This seeder is idempotent:
 * it only creates the owner when one does not already exist for the
 * configured username, so it is safe to re-run after fresh migrations.
 *
 * Credentials default to:
 *   username: owner       password: admin123
 * Override via env when security matters:
 *   SUPERX_OWNER_USERNAME, SUPERX_OWNER_PASSWORD,
 *   SUPERX_OWNER_NAME, SUPERX_OWNER_EMAIL
 */
class SuperxOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SUPERX_OWNER_USERNAME', 'owner');
        $plain = env('SUPERX_OWNER_PASSWORD', 'admin123');

        $exists = User::withoutGlobalScope(BusinessScope::class)
            ->where('role', 'superx_owner')
            ->where('username', $username)
            ->exists();

        if ($exists) {
            $this->command->info("SuperX owner '{$username}' already exists — skipping.");

            return;
        }

        if (strlen($plain) < 8) {
            $this->command->warn('Password is shorter than 8 characters; use a stronger one in production.');

            return;
        }

        User::withoutGlobalScope(BusinessScope::class)->create([
            'business_id' => null,
            'name' => env('SUPERX_OWNER_NAME', 'SuperX Platform Owner'),
            'email' => env('SUPERX_OWNER_EMAIL', 'owner@superx.com'),
            'username' => $username,
            'password' => Hash::make($plain),
            'role' => 'superx_owner',
            'is_active' => true,
        ]);

        $this->command->info("SuperX owner '{$username}' created. You can sign in at /login.");
    }
}
