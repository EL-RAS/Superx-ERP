/**
 * Single source of truth for tenant-subdomain detection.
 *
 * Shared by `middleware.ts` (Edge, reads the `Host` header), `lib/api.ts`
 * (browser, reads `location.hostname`) and the auth pages, so the three can
 * never disagree about which host belongs to a tenant.
 */

/**
 * The central (marketing + platform-owner) domain.
 *
 * Mirrors `config('superx.tenant_domain')` on the backend, which resolves
 * `CENTRAL_DOMAIN` -> legacy `SUPERX_TENANT_DOMAIN` -> `'superx-erp.com'`.
 * `NEXT_PUBLIC_*` values are inlined at build time, so this constant is
 * identical in the Edge middleware bundle and in the browser bundle.
 */
export const CENTRAL_DOMAIN = normalizeHost(
  process.env.NEXT_PUBLIC_SUPERX_BASE_DOMAIN || "superx-erp.com"
);

/**
 * Labels that sit in front of the central domain but never identify a tenant.
 * `www` is the marketing alias; the rest stop platform/infra hosts from being
 * mistaken for storefronts.
 */
const RESERVED_SUBDOMAINS = new Set([
  "www",
  "app",
  "api",
  "admin",
  "platform",
  "central",
  "mail",
  "static",
  "cdn",
]);

/** Strips scheme, port, path and a trailing dot; lowercases the host. */
export function normalizeHost(raw: string | null | undefined): string {
  return (raw ?? "")
    .trim()
    .toLowerCase()
    .replace(/^[a-z][a-z0-9+.-]*:\/\//, "")
    .replace(/\/.*$/, "")
    .replace(/:\d+$/, "")
    .replace(/\.$/, "");
}

/**
 * Returns the tenant slug for a tenant host, or `null` when the host is the
 * central domain (or is not tenant-shaped at all).
 *
 * Accepts `tt.superx-erp.com` in production and `tt.localhost` in local dev —
 * the latter mirrors the backend's `resolveTenantByHost()` dev mapping, so both
 * sides agree without either needing to know the other's environment.
 */
export function tenantSubdomainOf(rawHost: string | null | undefined): string | null {
  const host = normalizeHost(rawHost);
  if (!host) return null;

  for (const base of [CENTRAL_DOMAIN, "localhost"]) {
    if (!base || !host.endsWith(`.${base}`)) continue;

    const sub = host.slice(0, -(base.length + 1));
    // Reject deeper nesting (`a.b.superx-erp.com`) and reserved labels.
    if (!sub || sub.includes(".")) return null;

    return RESERVED_SUBDOMAINS.has(sub) ? null : sub;
  }

  return null;
}

/** True when the host is a tenant storefront rather than the central site. */
export function isTenantHost(rawHost: string | null | undefined): boolean {
  return tenantSubdomainOf(rawHost) !== null;
}

/**
 * Absolute URL of the central site. Used to send a visitor who hit an
 * unregistered subdomain back to the real homepage rather than stranding them
 * on a dead store address.
 */
export function centralSiteUrl(path = "/"): string {
  return `https://${CENTRAL_DOMAIN}${path}`;
}
