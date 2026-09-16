import { parsePhoneNumberFromString } from "libphonenumber-js";

// Regions frequently used by this app (defaults to Jordan +962). libphonenumber-js
// ships full international metadata, so any country code can still be parsed.
export type PhoneCountry =
  | "JO" | "SA" | "AE" | "EG" | "KW" | "QA" | "BH" | "OM" | "LB" | "IQ"
  | "SY" | "YE" | "PS" | "TR" | "US" | "CA" | "GB" | "DE" | "FR" | "ES"
  | "IT" | "NL" | "RU" | "IN" | "PK" | "BD" | "CN" | "JP" | "KR" | "AU";

/**
 * Normalize any phone input to E.164 (+962785555555). Handles the "+962" /
 * "00962" prefixes, national trunk zeros (+962 0785555555 -> +962785555555),
 * and bare national numbers (0785555555 -> +962785555555). Returns null when
 * the number is empty or not a valid number for its country.
 */
export function normalizePhone(input: string, defaultCountry: PhoneCountry = "JO"): string | null {
  const trimmed = input.trim();
  if (!trimmed) return null;
  try {
    const parsed = parsePhoneNumberFromString(trimmed, defaultCountry);
    return parsed?.isValid() ? parsed.number : null;
  } catch {
    return null;
  }
}

export function isValidPhone(input: string, defaultCountry: PhoneCountry = "JO"): boolean {
  return normalizePhone(input, defaultCountry) !== null;
}

const EMAIL_RE = /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i;

/**
 * Strict-but-practical RFC-style email check mirroring the backend
 * `email:rfc` rule (rejects "plainaddress", "a@", "@domain.com").
 */
export function isValidEmail(input: string): boolean {
  const value = input.trim();
  if (!value || value.length > 254) return false;
  if (value.startsWith(".") || value.includes("..")) return false;
  return EMAIL_RE.test(value);
}
