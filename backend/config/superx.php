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
    | generated under (e.g. `albaraka` -> `albaraka.superx.com`). Tenant URLs
    | (store, activation links, login) are built environment-aware: production
    | uses `{subdomain}.{tenant_domain}`, while local development swaps the host
    | for `{subdomain}.localhost` and carries the `SUPERX_FRONTEND_URL`
    | scheme/port (e.g. `http://albaraka.localhost:3000`).
    |
    */

    'tenant_domain' => env('SUPERX_TENANT_DOMAIN', 'superx.com'),

    'frontend_url' => env('SUPERX_FRONTEND_URL', 'http://localhost:3000'),

    // Lifespan (days) of the one-time activation link issued at provisioning.
    'activation_ttl_days' => (int) env('SUPERX_ACTIVATION_TTL_DAYS', 7),
];
