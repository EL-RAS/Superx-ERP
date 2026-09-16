export const MEASURED_UNITS = [
  "kg", "g", "mg", "gram", "grams", "kilo", "kilogram", "kilograms",
  "oz", "lb", "lbs", "pound", "pounds",
  "l", "liter", "liters", "litre", "litres", "ml",
  "tbsp", "tsp", "cup", "cups",
];

export interface QuantityInfo {
  is_weighable?: boolean | null;
  unit?: string | null;
}

export function isMeasuredProduct(p: QuantityInfo | null | undefined): boolean {
  if (!p) return false;
  if (p.is_weighable) return true;
  const unit = (p.unit ?? "").trim().toLowerCase();
  return unit !== "" && MEASURED_UNITS.includes(unit);
}

/** "1" for piece/count products, "0.001" for weight/measured products. */
export function quantityStep(p: QuantityInfo | null | undefined): string {
  return isMeasuredProduct(p) ? "0.001" : "1";
}