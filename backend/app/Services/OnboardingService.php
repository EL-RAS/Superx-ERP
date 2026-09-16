<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivationToken;
use App\Models\Business;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\DatabaseConfig;

/**
 * Orchestrates the B2B onboarding lifecycle:
 *   Lead → Approve & Provision → Activate wizard → Tenant login → Find my store.
 *
 * Hybrid architecture:
 *   - Central DB holds the Business mirror, Lead, ActivationToken, and
 *     the API-layer User record (for Sanctum tokens / X-Business-ID).
 *   - Tenant DB holds the shop profile (businesses) and the tenant-side
 *     User record (verified during domain-based login).
 */
class OnboardingService
{
    private const GENERIC_SETTINGS = [
        'allow_split_payments' => false,
        'allow_credit_sales' => false,
        'expiry_alerts' => false,
        'low_stock_sensitivity' => 'normal',
        'barcode_scanner' => true,
        'tax_enabled' => true,
        'default_tax_rate' => 16,
        'tax_calculation_method' => 'exclusive',
        'jofotara_enabled' => false,
    ];

    // ─── Helpers ───────────────────────────────────────────────────

    public function baseDomain(): string
    {
        return strtolower((string) config('superx.tenant_domain', 'superx.com'));
    }

    public function normalizeSubdomain(string $subdomain): string
    {
        return strtolower(trim($subdomain));
    }

    public function buildDomain(string $subdomain): string
    {
        return $this->normalizeSubdomain($subdomain).'.'.$this->baseDomain();
    }

    public function isSubdomainAvailable(string $subdomain): bool
    {
        $normalized = $this->normalizeSubdomain($subdomain);

        return $normalized !== ''
            && preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $normalized) === 1
            && ! Domain::where('domain', $this->buildDomain($normalized))->exists();
    }

    public function activationUrl(ActivationToken $token): string
    {
        $subdomain = Tenant::find($token->tenant_id)?->subdomain;

        if (! $subdomain) {
            $front = rtrim((string) config('superx.frontend_url', 'http://localhost:3000'), '/');

            return $front.'/activate?token='.$token->token;
        }

        return $this->storeUrl($this->buildDomain($subdomain), '/activate?token='.$token->token);
    }

    /**
     * Environment-aware store URL. In local development the production base
     * domain is swapped for `localhost` and the dev origin's scheme/port are
     * carried over (e.g. `http://brillivo.localhost:3000`) so tenant links
     * resolve on the developer's machine instead of a hardcoded production
     * host. In production the canonical `{subdomain}.{baseDomain}` is used.
     */
    public function storeUrl(string $domain, string $path = ''): string
    {
        $front = rtrim((string) config('superx.frontend_url', 'http://localhost:3000'), '/');
        $frontHost = strtolower(parse_url($front, PHP_URL_HOST) ?: 'localhost');
        $scheme = parse_url($front, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($front, PHP_URL_PORT);

        if (in_array($frontHost, ['localhost', '127.0.0.1'], true)) {
            $subdomain = Str::before($domain, '.');
            $host = $subdomain.'.localhost'.($port ? ':'.$port : '');
        } else {
            $host = $domain;
        }

        $path = ltrim($path, '/');

        return $scheme.'://'.$host.($path !== '' ? '/'.$path : '');
    }

    // ─── Provisioning (owner approval) ────────────────────────────

    public function provision(Lead $lead, ?User $owner = null): array
    {
        if ($lead->status !== 'new') {
            throw ValidationException::withMessages([
                'lead' => [$lead->isProvisioned() ? 'This lead has already been provisioned.' : 'This lead can no longer be approved.'],
            ]);
        }

        // Plan + subscription term chosen by the owner at approval time (stored
        // on the lead metadata). sanitise again here so direct calls can never
        // sneak in an arbitrary plan.
        $plan = (string) ($lead->metadata['plan'] ?? 'standard');
        if (! in_array($plan, ['standard', 'premium', 'enterprise'], true)) {
            $plan = 'standard';
        }
        $days = max(1, (int) ($lead->metadata['subscription_days'] ?? 30));
        $expiresAt = now()->addDays($days)->toDateString();

        $subdomain = $this->resolveSubdomain($lead);
        $businessId = (string) Str::uuid();

        $type = $lead->businessType;
        $typeSettings = $type?->default_settings ?? [];

        // 1. Central mirror Business (created hook seeds RBAC + merges type defaults).
        $business = Business::create([
            'id' => $businessId,
            'business_type_id' => $lead->business_type_id,
            'name' => $lead->business_name,
            'slug' => $this->uniqueSlug($lead->business_name),
            'status' => 'activating',
            'plan' => $plan,
            'contact_phone' => $lead->phone,
            'city' => $lead->city,
            'settings' => $typeSettings,
            'subscription_starts_at' => now()->toDateString(),
            'expires_at' => $expiresAt,
        ]);

        $tenant = null;

        try {
            // 2. Stancl Tenant + pipeline (CreateDatabase / MigrateDatabase / SeedDatabase).
            $domain = $this->buildDomain($subdomain);

            // Stancl's VirtualColumn trait re-encodes every non-column attribute
            // into the `data` jsonb column on save — passing a nested `data`
            // array would be dropped (encoded to []). Set the keys top-level so
            // encodeAttributes() folds them into data.* (business_id, subdomain,
            // domain, lead_id, activated_at, shop) for TenantDatabaseSeeder.
            $tenant = Tenant::create([
                'id' => $businessId,
                'business_id' => $businessId,
                'subdomain' => $subdomain,
                'domain' => $domain,
                'lead_id' => $lead->id,
                'activated_at' => null,
                'shop' => [
                    'id' => $businessId,
                    'name' => $lead->business_name,
                    'slug' => $business->slug,
                    'plan' => $plan,
                    'city' => $lead->city,
                    'contact_phone' => $lead->phone,
                    'business_type_id' => $lead->business_type_id,
                    'status' => 'activating',
                    'settings' => array_merge(self::GENERIC_SETTINGS, $typeSettings),
                    'subscription_starts_at' => now()->toDateString(),
                    'expires_at' => $expiresAt,
                ],
            ]);

            $tenant->domains()->create(['domain' => $domain]);

            // 3. Activation token (single-use, expiring).
            $ttlDays = (int) config('superx.activation_ttl_days', 7);
            $token = ActivationToken::create([
                'lead_id' => $lead->id,
                'tenant_id' => $tenant->id,
                'business_id' => $businessId,
                'token' => Str::random(64),
                'expires_at' => now()->addDays($ttlDays),
            ]);

            // 4. Stamp the lead.
            $lead->update([
                'subdomain' => $subdomain,
                'tenant_business_id' => $businessId,
                'status' => 'provisioned',
                'approved_by' => $owner?->id,
                'provisioned_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Roll back partial state: tenant DB (if created) + central Business.
            if ($tenant) {
                try {
                    $tenant->delete();
                } catch (\Throwable) {
                    // best-effort
                }
            }
            $business->delete();
            $lead->update(['status' => 'new', 'tenant_business_id' => null]);

            throw $e;
        }

        return [
            'lead' => $lead->fresh(),
            'tenant' => $tenant,
            'business' => $business,
            'activation' => $token,
            'domain' => $domain,
            'subdomain' => $subdomain,
            'database' => (new DatabaseConfig($tenant))->getName(),
            'store_url' => $this->storeUrl($domain),
            'activation_url' => $this->activationUrl($token),
        ];
    }

    // ─── Activation wizard ────────────────────────────────────────

    public function activationPreview(string $rawToken): array
    {
        $activation = $this->validToken($rawToken);

        $business = Business::with('businessType')->find($activation->business_id);
        $tenant = Tenant::find($activation->tenant_id);
        $lead = $activation->lead;
        $domain = $tenant?->domains()->first()?->domain;

        return [
            'token' => $activation->token,
            'expires_at' => $activation->expires_at->toIso8601String(),
            'business' => [
                'name' => $business->name ?? $lead?->business_name,
                'slug' => $business->slug ?? null,
                'business_type' => $business->businessType ? [
                    'slug' => $business->businessType->slug,
                    'name_en' => $business->businessType->name_en,
                    'name_ar' => $business->businessType->name_ar,
                ] : null,
                'subdomain' => $tenant?->subdomain,
                'domain' => $domain,
                'city' => $business->city ?? $lead?->city,
                'contact' => [
                    'name' => $lead?->name,
                    'email' => $lead?->email,
                    'phone' => $lead?->phone ?? $business->contact_phone,
                ],
            ],
        ];
    }

    public function activate(string $rawToken, array $payload): array
    {
        $activation = $this->validToken($rawToken);
        $businessId = $activation->business_id;
        $tenant = Tenant::find($activation->tenant_id);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'token' => ['Tenant not found. Please contact support.'],
            ]);
        }

        $name = $payload['name'] ?? $activation->lead?->name ?? 'Admin';
        $email = $payload['email'] ?? $activation->lead?->email ?? '';
        $password = $payload['password'];
        $currency = $payload['currency'] ?? 'JOD';
        $taxEnabled = $payload['tax_enabled'] ?? true;
        $defaultTaxRate = $payload['default_tax_rate'] ?? 16;
        $taxMethod = $payload['tax_calculation_method'] ?? 'exclusive';

        // 1. Create root admin user INSIDE the tenant DB. A custom username
        // (e.g. `brillivo_admin`) is preferred; otherwise one is derived from
        // the email. The same handle must be unique in BOTH scopes (tenant DB
        // + central mirror) so subdomain login resolves consistently.
        $customUsername = isset($payload['username'])
            ? preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $payload['username'])))
            : '';
        $baseUsername = $customUsername !== ''
            ? $customUsername
            : $this->deriveUsername($email, fn () => false);
        $username = $this->uniqueUsername($tenant, $baseUsername);
        $hashedPassword = Hash::make($password);

        $tenant->run(function () use (
            $businessId, $name, $email, $username, $hashedPassword,
        ) {
            DB::table('users')->insert([
                'business_id' => $businessId,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password' => $hashedPassword,
                'role' => 'admin',
                'is_active' => true,
                'is_primary_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // 2. Activate tenant in the tenant DB (shop status → active).
        $shopSettings = array_merge(
            $tenant->shop['settings'] ?? [],
            ['currency' => $currency, 'tax_enabled' => $taxEnabled,
                'default_tax_rate' => $defaultTaxRate, 'tax_calculation_method' => $taxMethod],
        );

        $tenant->run(function () use ($businessId, $shopSettings) {
            DB::table('businesses')
                ->where('id', $businessId)
                ->update([
                    'status' => 'active',
                    'settings' => json_encode($shopSettings),
                    'updated_at' => now(),
                ]);
        });

        // 3. Mark tenant as activated in central metadata. Update the virtual
        // attribute directly — a nested `data` key is ignored by VirtualColumn.
        $tenant->activated_at = now()->toIso8601String();
        $tenant->save();

        // 4. Create central mirror User (for Sanctum / API auth).
        $mirror = User::withoutBusiness()->create([
            'business_id' => $businessId,
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => $hashedPassword,
            'role' => 'admin',
            'is_active' => true,
            'is_primary_admin' => true,
        ]);

        // 5. Activate central Business mirror.
        $business = Business::find($businessId);
        $business->update([
            'status' => 'active',
            'settings' => $shopSettings,
        ]);

        // 6. Consume the activation token.
        $activation->consume();

        $domain = $tenant->domains()->first()?->domain;

        return [
            'ok' => true,
            'message' => 'Your store is ready.',
            'business' => [
                'id' => $businessId,
                'name' => $business->name,
                'slug' => $business->slug,
            ],
            'onboarding' => [
                'username' => $username,
                'subdomain' => $tenant->subdomain,
                'domain' => $domain,
                'login_url' => $this->buildLoginUrl($domain),
            ],
        ];
    }

    // ─── Tenant-domain login ──────────────────────────────────────

    public function loginByHost(string $host, string $username, string $password): array
    {
        $tenant = $this->resolveTenantByHost($host);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'host' => ['No store is registered for this address.'],
            ]);
        }

        $businessId = (string) $tenant->business_id;
        $activated = $tenant->activated_at;

        // Not yet activated — send them to the wizard.
        if (! $activated) {
            $pendingToken = ActivationToken::where('tenant_id', $tenant->id)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->first();

            return [
                'needs_activation' => true,
                'business_name' => $tenant->shop['name'] ?? null,
                'activation_url' => $pendingToken ? $this->activationUrl($pendingToken) : null,
                'subdomain' => $tenant->subdomain,
            ];
        }

        // Verify credentials against the tenant DB.
        $tenantUser = null;

        $tenant->run(function () use ($businessId, $username, $password, &$tenantUser) {
            $candidate = DB::table('users')
                ->where('business_id', $businessId)
                ->where(function ($q) use ($username) {
                    $q->where('username', $username)
                        ->orWhere('email', $username);
                })
                ->first();

            if ($candidate && Hash::check($password, $candidate->password) && $candidate->is_active) {
                $tenantUser = $candidate;
            }
        });

        if (! $tenantUser) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Issue a central Sanctum token from the mirror User.
        $mirrorUser = User::withoutBusiness()
            ->where('business_id', $businessId)
            ->where('username', $tenantUser->username)
            ->first();

        // Fallback: create central mirror if it was somehow lost. The mirror
        // replicates the TENANT-side record (role, activity, primary flag) so a
        // recovered session never upgrades a cashier to admin.
        if (! $mirrorUser) {
            $mirrorUser = User::withoutBusiness()->create([
                'business_id' => $businessId,
                'name' => $tenantUser->name,
                'email' => $tenantUser->email,
                'username' => $tenantUser->username,
                'password' => $tenantUser->password,
                'role' => $tenantUser->role,
                'is_active' => (bool) $tenantUser->is_active,
                'is_primary_admin' => (bool) $tenantUser->is_primary_admin,
            ]);
        }

        $business = Business::find($businessId);
        $business->load('businessType');

        $token = $mirrorUser->createToken('auth-token')->plainTextToken;

        return [
            'token' => $token,
            'user' => [
                'id' => $mirrorUser->id,
                'name' => $mirrorUser->name,
                'email' => $mirrorUser->email,
                'username' => $mirrorUser->username,
                'role' => $mirrorUser->role,
                'is_active' => (bool) $mirrorUser->is_active,
                'is_platform_owner' => false,
            ],
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'status' => $business->status,
                'business_type' => $business->businessType ? [
                    'id' => $business->businessType->id,
                    'slug' => $business->businessType->slug,
                    'name_en' => $business->businessType->name_en,
                    'name_ar' => $business->businessType->name_ar,
                ] : null,
                'subscription' => $business->subscriptionPayload(),
            ],
        ];
    }

    // ─── Internal helpers ─────────────────────────────────────────

    private function resolveSubdomain(Lead $lead): string
    {
        $raw = $lead->subdomain;

        if ($raw && $this->isSubdomainAvailable($raw)) {
            return $this->normalizeSubdomain($raw);
        }

        // Auto-generate from business name.
        $base = Str::slug($lead->business_name);
        $candidate = $base;
        $i = 1;

        while (! $this->isSubdomainAvailable($candidate)) {
            $candidate = $base.'-'.(++$i);
        }

        return $this->normalizeSubdomain($candidate);
    }

    private function validToken(string $rawToken): ActivationToken
    {
        $activation = ActivationToken::where('token', $rawToken)->first();

        if (! $activation || ! $activation->isValid()) {
            throw ValidationException::withMessages([
                'token' => ['This activation link is invalid or has expired.'],
            ]);
        }

        return $activation;
    }

    private function resolveTenantByHost(string $host): ?Tenant
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host); // strip port
        $host = preg_replace('#^[a-z]+://#', '', $host); // strip scheme

        // Local dev: dev URLs are `{subdomain}.localhost` — map back to the
        // canonical `{subdomain}.{baseDomain}` before the domain lookup.
        if (str_ends_with($host, '.localhost')) {
            $host = substr($host, 0, -10).'.'.$this->baseDomain();
        }

        $domain = Domain::where('domain', $host)->first();
        if ($domain) {
            return $domain->tenant;
        }

        // Try base domain: "mystore" → "mystore.superx.com"
        if (! str_contains($host, '.')) {
            $domain = Domain::where('domain', $this->buildDomain($host))->first();
            if ($domain) {
                return $domain->tenant;
            }
        }

        return null;
    }

    private function buildLoginUrl(?string $domain): string
    {
        if (! $domain) {
            $front = rtrim((string) config('superx.frontend_url', 'http://localhost:3000'), '/');

            return $front.'/login';
        }

        return $this->storeUrl($domain, '/login');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $i = 1;

        while (Business::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    /**
     * Resolves a handle unique within ONE tenant's login scope: unique in the
     * tenant DB users table AND among that business's OWN central mirror rows.
     * Usernames are deliberately NOT unique across businesses — two tenants may
     * both adopt `admin` (their credential stores are separate databases).
     */
    private function uniqueUsername(Tenant $tenant, string $base): string
    {
        $businessId = (string) $tenant->business_id;
        $candidate = $base;
        $i = 1;

        while (
            $this->usernameExistsInTenant($tenant, $candidate)
            || User::withoutBusiness()
                ->where('business_id', $businessId)
                ->where('username', $candidate)
                ->exists()
        ) {
            $candidate = $base.'_'.(++$i);
        }

        return $candidate;
    }

    private function deriveUsername(string $email, callable $exists): string
    {
        $local = strtolower(preg_replace('/[^a-zA-Z0-9._%+-]/', '', Str::before($email, '@')));
        $local = preg_replace('/[^a-z0-9_]/', '_', $local);
        $local = preg_replace('/_+/', '_', trim($local, '_'));
        $local = substr($local, 0, 30) ?: 'admin';

        $candidate = $local;
        $i = 1;

        while ($exists($candidate)) {
            $candidate = $local.'_'.(++$i);
        }

        return $candidate;
    }

    private function usernameExistsInTenant(Tenant $tenant, string $username): bool
    {
        $businessId = (string) $tenant->business_id;
        $exists = false;

        $tenant->run(function () use ($businessId, $username, &$exists) {
            $exists = DB::table('users')
                ->where('business_id', $businessId)
                ->where('username', $username)
                ->exists();
        });

        return $exists;
    }
}
