"use client";

import { usePathname, useRouter } from "next/navigation";
import { useI18n, type Locale } from "@/lib/i18n";

/** A leading locale segment written by the middleware rewrite (`/ar/login`). */
const LOCALE_PREFIX = /^\/(en|ar)(?=\/|$)/;

interface LanguageToggleProps {
  className?: string;
}

/**
 * Language switcher for the public/central pages.
 *
 * The locale lives in `I18nProvider` (client state), so the toggle has to
 * change that state directly — navigating to `/ar` instead would render the
 * very same page through the middleware rewrite, leave the root layout (and
 * therefore the provider state) untouched, and silently do nothing until a
 * manual refresh. The URL is then reconciled so the address bar always agrees
 * with the active language: a stale `/ar` would flip the page back to Arabic
 * on the next refresh.
 */
export default function LanguageToggle({ className }: LanguageToggleProps) {
  const { locale, setLocale } = useI18n();
  const router = useRouter();
  const pathname = usePathname();

  const switchTo = (next: Locale) => {
    // Synchronous: re-renders the tree in place and persists the choice to
    // both the `sx_locale` cookie and localStorage. No server round trip.
    setLocale(next);

    // `usePathname` can report either the browser path or the rewritten one
    // depending on the Next version, so strip the prefix defensively — both
    // shapes collapse to the same target.
    const rest = pathname.replace(LOCALE_PREFIX, "") || "/";
    router.replace(next === "ar" ? `/ar${rest === "/" ? "" : rest}` : rest);
  };

  return (
    <button
      type="button"
      onClick={() => switchTo(locale === "en" ? "ar" : "en")}
      className={className}
      // The label names the *other* language, so hint its own language for
      // screen readers.
      lang={locale === "en" ? "ar" : "en"}
    >
      {locale === "en" ? "عربي" : "EN"}
    </button>
  );
}
