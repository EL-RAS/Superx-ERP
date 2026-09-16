<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Scopes\BusinessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('username', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->has('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = (string) $request->user()->business_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('business_id', $businessId)],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->where('business_id', $businessId),
            ],
            'password' => ['required', new StrongPassword],
            'role' => ['nullable', 'string', 'max:50', Rule::exists('roles', 'slug')->where('business_id', $businessId)],
            'is_active' => 'nullable|boolean',
        ]);

        $validated['business_id'] = $businessId;
        $hashedPassword = Hash::make($validated['password']);
        $validated['password'] = $hashedPassword;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['is_primary_admin'] = false;

        // Subscription cap: tenants limited to N POS registers cannot add an
        // (active) user with POS access beyond that seat count.
        if ($validated['is_active']) {
            $business = Business::query()
                ->withoutGlobalScope(BusinessScope::class)
                ->findOrFail($validated['business_id']);

            if (! $business->hasPosSeatAvailable()) {
                return response()->json([
                    'message' => "Your plan allows a maximum of {$business->max_pos_registers} POS register(s). Please contact SuperX support to upgrade.",
                    'code' => 'pos_seat_limit_reached',
                ], 422);
            }
        }

        $user = User::create($validated);

        // Mirror into the tenant's own database so the new account can sign in
        // through the store subdomain (tenant-login authenticates there).
        $this->syncTenantUser($businessId, $this->tenantUserPayload($user, $hashedPassword));

        return response()->json($user, 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->where('business_id', $user->business_id)->ignore($user->id)],
            'username' => [
                'sometimes',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->where('business_id', $user->business_id)->ignore($user->id),
            ],
            'password' => ['nullable', new StrongPassword],
            'role' => ['nullable', 'string', 'max:50', Rule::exists('roles', 'slug')->where('business_id', $user->business_id)],
            'is_active' => 'nullable|boolean',
        ]);

        if ($user->isPrimaryAdmin() && array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            return response()->json(['message' => 'The primary admin account cannot be deactivated.'], 422);
        }

        $originalUsername = (string) $user->getOriginal('username');
        $hashedPassword = null;

        if (! empty($validated['password'])) {
            $hashedPassword = Hash::make($validated['password']);
            $validated['password'] = $hashedPassword;
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        // Keep the tenant-side credential store in lock-step with the central
        // mirror (username-renames match on the pre-update handle).
        $this->syncTenantUser(
            (string) $user->business_id,
            $this->tenantUserPayload($user, $hashedPassword ?? (string) $user->getOriginal('password')),
            $originalUsername,
        );

        return response()->json($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->isPrimaryAdmin()) {
            return response()->json(['message' => 'The primary admin account cannot be deleted.'], 403);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $businessId = (string) $user->business_id;
        $username = (string) $user->username;

        $user->delete();

        $tenant = Tenant::find($businessId);

        if ($tenant) {
            $tenant->run(function () use ($businessId, $username) {
                DB::table('users')
                    ->where('business_id', $businessId)
                    ->where('username', $username)
                    ->delete();
            });
        }

        return response()->json(['message' => 'User deleted.']);
    }

    /**
     * Column payload persisted to the tenant's own users table (the store that
     * tenant-login authenticates against). Passwords are inserted pre-hashed â€”
     * query-builder inserts never touch the Eloquent `hashed` cast.
     */
    private function tenantUserPayload(User $user, string $hashedPassword): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'password' => $hashedPassword,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'is_primary_admin' => (bool) $user->is_primary_admin,
            'avatar' => $user->avatar,
        ];
    }

    /**
     * Upsert the user row into the tenant database for $businessId (matched by
     * username, falling back to a plain insert when the row is missing).
     * Manual-legacy businesses without a stancl tenant row are skipped â€” their
     * accounts exist only in the central mirror.
     */
    private function syncTenantUser(string $businessId, array $data, ?string $matchUsername = null): void
    {
        $tenant = Tenant::find($businessId);

        if (! $tenant) {
            return;
        }

        $tenant->run(function () use ($businessId, $data, $matchUsername) {
            $query = DB::table('users')
                ->where('business_id', $businessId)
                ->where('username', $matchUsername ?? $data['username']);

            if ($query->exists()) {
                $query->update($data + ['updated_at' => now()]);
            } else {
                DB::table('users')->insert($data + [
                    'business_id' => $businessId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
