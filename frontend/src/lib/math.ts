/**
 * Round a number to 2 decimal places (standard currency rounding).
 * Used by invoice, return, and GRN forms to avoid floating-point drift.
 */
export function round2(n: number): number {
  return Math.round(n * 100) / 100;
}
