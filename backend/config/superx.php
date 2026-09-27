<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SuperX Platform Owner
    |--------------------------------------------------------------------------
    |
    | The platform-owner surface (tenant directory, provisioning, subscription
    | management) requires an authenticated user with the `superx_owner` role.
    | As a second, explicit channel for server-to-server access, requests may
    | present this shared secret in the `X-Owner-Secret` header. Keep it empty
    | to disable the header channel entirely.
    |
    */

    'owner_role' => env('SUPERX_OWNER_ROLE', 'superx_owner'),

    'owner_secret' => env('SUPERX_OWNER_SECRET', ''),

    // WhatsApp number (international format, digits only) shown on the
    // subscription-expired lockout screen and support prompts.
    'support_whatsapp' => env('SUPERX_SUPPORT_WHATSAPP', '962790000000'),

    /*
    |--------------------------------------------------------------------------
    | B2B Onboarding / Multi-tenant Setup
    |--------------------------------------------------------------------------
    |
    | `tenant_domain` is the base domain that tenant store subdomains are
    | generated under (e.g. `albaraka` -> `albaraka.superx-erp.com`). It is the
    | single source of truth for the base domain — `config/tenancy.php`
    | resolves `central_domains` from the SAME env vars, so the two can never
    | disagree. Precedence: `CENTRAL_DOMAIN`, then the legacy
    | `SUPERX_TENANT_DOMAIN`, then the fallback below. Set `CENTRAL_DOMAIN` in
    | every deployed environment; leaving it unset makes the config default
    | decide production behaviour.
    |
    */

    'tenant_domain' => strtolower((string) (env('CENTRAL_DOMAIN') ?: env('SUPERX_TENANT_DOMAIN') ?: 'superx-erp.com')),

    /*
    | Frontend origin used to build tenant store URLs (store, activation links,
    | login). Leave it EMPTY in production: URLs are then derived from
    | `tenant_domain` over https, so a missing env var can never leak
    | `localhost` into owner-facing links. Set it in local development to the
    | Next.js origin (e.g. `http://localhost:3000`).
    */
    'frontend_url' => rtrim((string) env('SUPERX_FRONTEND_URL', ''), '/'),

    /*
    | Whether tenant URLs collapse onto `{subdomain}.localhost` instead of
    | `{subdomain}.{tenant_domain}`. Defaults to true for local/testing and
    | false everywhere else, and `OnboardingService::storeUrl()` additionally
    | requires `frontend_url` to be a loopback host — so a production deploy
    | that forgets (or inherits) the dev value still emits production URLs.
    */
    'dev_tenant_subdomains' => filter_var(
        env('SUPERX_TENANT_DEV_SUBDOMAINS', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        FILTER_VALIDATE_BOOL
    ),

    // Lifespan (days) of the one-time activation link issued at provisioning.
    'activation_ttl_days' => (int) env('SUPERX_ACTIVATION_TTL_DAYS', 7),
];
