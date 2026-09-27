import { NextRequest, NextResponse } from "next/server";

const AUTH_COOKIE = "sx_auth";
const LOCALE_COOKIE = "sx_locale";

/**
 * Locales that may appear as a leading path segment, e.g. `/ar`,
 * `/ar/login`, `/en/dashboard`. Keep in sync with `Locale` in `lib/i18n`.
 */
const LOCALES = ["en", "ar"] as const;

/** Routes an authenticated user is redirected away from. */
const GUARDED_ROUTES = ["/", "/login", "/register"];

function isLocale(value: string): value is (typeof LOCALES)[number] {
  return (LOCALES as readonly string[]).includes(value);
}

/**
 * Attaches the locale cookie so `I18nProvider` (client-side) renders the right
 * language/direction on the very next paint. A `rewrite` does not change the
 * browser URL, so the address bar keeps the `/ar` prefix while the request
 * internally resolves to the locale-agnostic route.
 */
function withLocale(response: NextResponse, locale: string | null): NextResponse {
  if (locale) {
    response.cookies.set(LOCALE_COOKIE, locale, {
      path: "/",
      sameSite: "lax",
      maxAge: 60 * 60 * 24 * 365,
    });
  }

  return response;
}

export function middleware(request: NextRequest) {
  const token = request.cookies.get(AUTH_COOKIE)?.value;
  const { pathname } = request.nextUrl;

  // The app has no `[lang]` route segment — i18n lives entirely in
  // `I18nProvider`. A leading locale segment is therefore a marker, not a
  // route: strip it and let the real route handle the request.
  const segments = pathname.split("/").filter(Boolean);
  const head = (segments[0] ?? "").toLowerCase();
  const locale = isLocale(head) ? head : null;
  const route = locale ? `/${segments.slice(1).join("/")}` : pathname;

  const url = request.nextUrl.clone();

  // The auth guard runs against the resolved route, so `/ar` is recognised as
  // the landing page rather than falling through as an unknown path.
  if (token && GUARDED_ROUTES.includes(route)) {
    url.pathname = "/dashboard";

    return withLocale(NextResponse.redirect(url), locale);
  }

  if (locale) {
    url.pathname = route;

    return withLocale(NextResponse.rewrite(url), locale);
  }

  return NextResponse.next();
}

export const config = {
  // Must be a static literal — Next.js parses this at build time, so the paths
  // are spelled out rather than generated. Keep the locale entries in sync
  // with LOCALES above.
  matcher: [
    "/",
    "/login",
    "/register",
    "/en",
    "/ar",
    "/en/:path*",
    "/ar/:path*",
  ],
};
