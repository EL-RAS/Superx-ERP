import { ApiError } from "./api";

/**
 * Extract per-field server-side validation errors (422) into a flat
 * field -> message map suitable for inline form display.
 */
export function mapFieldErrors(
  err: unknown,
  fields: string[]
): Record<string, string> {
  const result: Record<string, string> = {};
  if (err instanceof ApiError && err.errors) {
    for (const field of fields) {
      const msgs = err.errors[field];
      if (msgs && msgs.length > 0) {
        result[field] = msgs[0];
      }
    }
  }
  return result;
}
