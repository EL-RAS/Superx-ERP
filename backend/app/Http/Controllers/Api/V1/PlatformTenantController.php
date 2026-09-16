<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Rules\ValidPhone;
use App\Services\OnboardingService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\DatabaseConfig;

/**
 * SuperX Owner Portal API — tenant directory, provisioning and
 * subscription management. Every route is wrapped in the
 * `platform.owner` middleware (role `superx_owner` or env secret).
 */
class PlatformTenantController extends Controller
{
    public function index(): JsonResponse
    {
        $businesses = Business::query()
            ->with(['businessType:id,slug,name_en,name_ar'])
            ->withCount('users')
            ->orderBy('created_at', 'desc')
            ->get();

        // Preload tenant-directory context to avoid N+1 queries per business:
        // the originating lead (owner contact fallback), the stancl tenant row
        // (subdomain + physical database name) and the tenant's domain.
        $leads = Lead::whereIn('tenant_business_id', $businesses->pluck('id')->filter()->all())
            ->get()
            ->keyBy('tenant_business_id');
        $tenants = Tenant::findMany($businesses->pluck('id'))->keyBy('id');
        $domains = Domain::whereIn('tenant_id', $tenants->keys()->all())
            ->get()
            ->groupBy('tenant_id')
            ->map->first();

        $tenants = $businesses->map(fn (Business $business) => $this->tenantPayload(
            $business,
            $leads->get($business->id),
            $tenants->get($business->id),
            $domains->get($business->id)?->domain,
        ))->values();

        return response()->json([
            'summary' => [
                'total' => $tenants->count(),
                'active' => $tenants->where('status', 'active')->count(),
                'suspended' => $tenants->where('status', 'suspended')->count(),
                'expired' => $tenants->where('subscription.state', 'expired')->count(),
                'expiring_soon' => $tenants->where('subscription.state', 'expiring_soon')->count(),
            ],
            'tenants' => $tenants,
        ]);
    }

    public function show(Business $business): JsonResponse
    {
        $payload = $this->tenantPayload($business->load('businessType'));
        $payload['users'] = $business->users()->orderBy('created_at')->get([
            'id', 'name', 'username', 'email', 'role', 'is_active', 'created_at',
        ]);

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = (string) Str::uuid();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type_id' => ['required', 'integer', Rule::exists('business_types', 'id')],
            'plan' => 'nullable|string|max:50',
            'contact_phone' => ['nullable', new ValidPhone],
            'city' => 'nullable|string|max:255',
            'subscription_starts_at' => 'nullable|date',
            'expires_at' => [
                'nullable',
                'date',
                'after_or_equal:'.($request->input('subscription_starts_at') ?? now()->toDateString()),
            ],
            'max_pos_registers' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['active', 'suspended'])],

            'admin_name' => 'required|string|max:255',
            'admin_username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->where('business_id', $businessId),
            ],
            'admin_email' => 'required|email|max:255',
            'admin_password' => ['required', 'confirmed', new StrongPassword],
        ]);

        $business = DB::transaction(function () use ($validated, $businessId) {
            $business = Business::create([
                'id' => $businessId,
                'business_type_id' => $validated['business_type_id'],
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'status' => $validated['status'] ?? 'active',
                'plan' => $validated['plan'] ?? 'standard',
                'contact_phone' => isset($validated['contact_phone'])
                    ? PhoneNormalizer::normalize($validated['contact_phone'])
                    : null,
                'city' => $validated['city'] ?? null,
                'subscription_starts_at' => $validated['subscription_starts_at'] ?? now()->toDateString(),
                'expires_at' => $validated['expires_at'] ?? null,
                'max_pos_registers' => $validated['max_pos_registers'] ?? null,
            ]);

            User::create([
                'business_id' => $business->id,
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'username' => $validated['admin_username'],
                'password' => Hash::make($validated['admin_password']),
                'role' => 'admin',
                'is_active' => true,
                'is_primary_admin' => true,
            ]);

            return $business;
        });

        return response()->json($this->tenantPayload($business->load('businessType')), 201);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'plan' => 'sometimes|nullable|string|max:50',
            'contact_phone' => 'sometimes|nullable|string|max:32',
            'city' => 'sometimes|nullable|string|max:255',
            'subscription_starts_at' => 'sometimes|nullable|date',
            'expires_at' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:'.($request->input('subscription_starts_at') ?? $business->subscription_starts_at?->toDateString() ?? '1970-01-01'),
            ],
            'max_pos_registers' => 'sometimes|nullable|integer|min:0',
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],

            // Optional admin credential rotation / profile fix-up
            'admin_name' => 'sometimes|nullable|string|max:255',
            'admin_email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where('business_id', $business->id)->ignore(
                    $business->users()->where('is_primary_admin', true)->value('id')
                ),
            ],
            'admin_password' => ['sometimes', 'nullable', 'confirmed', new StrongPassword],
        ]);

        DB::transaction(function () use ($validated, $business) {
            $business->fill(collect($validated)->except([
                'admin_name', 'admin_email', 'admin_password',
            ])->toArray());
            $business->save();

            $admin = $business->users()->where('is_primary_admin', true)->first();

            if ($admin) {
                $adminUpdate = collect($validated)->only(['admin_name', 'admin_email'])->filter()->all();

                if (! empty($validated['admin_password'])) {
                    $adminUpdate['password'] = Hash::make($validated['admin_password']);
                }

                if ($adminUpdate !== []) {
                    $admin->update($adminUpdate);
                }
            } elseif (isset($validated['admin_password']) || isset($validated['admin_name'])) {
                throw ValidationException::withMessages([
                    'admin_name' => ['This business has no primary admin to update.'],
                ]);
            }
        });

        return response()->json($this->tenantPayload($business->load('businessType')));
    }

    private function tenantPayload(Business $business, ?Lead $lead = null, ?Tenant $tenant = null, ?string $domain = null): array
    {
        // Fall back to direct lookups when called outside index() (show/store/update).
        if ($tenant === null) {
            $tenant = Tenant::find($business->id);
        }
        if ($domain === null) {
            $domain = $tenant?->domains()->first()?->domain;
        }
        if ($lead === null) {
            $lead = Lead::where('tenant_business_id', $business->id)->first();
        }

        $admin = $business->users()->where('is_primary_admin', true)->first();

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'status' => $business->status,
            'plan' => $business->plan,
            'subdomain' => $tenant?->subdomain,
            'domain' => $domain,
            'database' => $tenant ? (new DatabaseConfig($tenant))->getName() : null,
            'store_url' => $domain ? app(OnboardingService::class)->storeUrl($domain) : null,
            'owner_contact' => [
                // Lead-provisioned tenants have no central users yet — fall back
                // to the originating lead's contact (the wizard creates the admin).
                'name' => $admin?->name ?? $lead?->name,
                'email' => $admin?->email ?? $lead?->email,
                'phone' => $lead?->phone ?? $business->contact_phone,
            ],
            'city' => $business->city,
            'business_type' => $business->businessType ? [
                'id' => $business->businessType->id,
                'slug' => $business->businessType->slug,
                'name_en' => $business->businessType->name_en,
                'name_ar' => $business->businessType->name_ar,
            ] : null,
            'pos_terminals' => [
                'used' => $business->posSeatsUsed(),
                'max' => $business->max_pos_registers,
            ],
            'users_count' => $business->users_count ?? $business->users()->count(),
            'subscription' => $business->subscriptionPayload(),
            'created_at' => $business->created_at?->toIso8601String(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $i = 1;

        while (Business::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
