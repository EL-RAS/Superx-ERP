import Cookies from "js-cookie";

const COOKIE_NAME = "sx_auth";
const COOKIE_DAYS = 30;

/**
 * Mirrors the locale chosen by `I18nProvider`. `middleware.ts` writes this
 * cookie from a locale-prefixed path (`/ar`) so the server can read the
 * visitor's language; keeping the client write here means the cookie and
 * localStorage never disagree.
 */
const LOCALE_COOKIE_NAME = "sx_locale";
const LOCALES = ["en", "ar"] as const;

export type StoredLocale = (typeof LOCALES)[number];

export function setLocaleCookie(locale: StoredLocale) {
  Cookies.set(LOCALE_COOKIE_NAME, locale, {
    expires: COOKIE_DAYS,
    path: "/",
    sameSite: "lax",
  });
}

export function getLocaleCookie(): StoredLocale | undefined {
  const value = Cookies.get(LOCALE_COOKIE_NAME);

  return value && (LOCALES as readonly string[]).includes(value)
    ? (value as StoredLocale)
    : undefined;
}

export function setAuthCookie(token: string) {
  Cookies.set(COOKIE_NAME, token, {
    expires: COOKIE_DAYS,
    path: "/",
    sameSite: "lax",
  });
}

export function getAuthCookie(): string | undefined {
  return Cookies.get(COOKIE_NAME);
}

export function removeAuthCookie() {
  Cookies.remove(COOKIE_NAME, { path: "/" });
}
