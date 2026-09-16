"use client";
import { useMemo } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useI18n } from "@/lib/i18n";

export interface PaginationProps {
  total: number;
  page: number;
  perPage: number;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  pageSizeOptions?: number[];
}

function pageItems(current: number, last: number): (number | "…")[] {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1);
  }
  const items: (number | "…")[] = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);
  if (start > 2) items.push("…");
  for (let i = start; i <= end; i++) items.push(i);
  if (end < last - 1) items.push("…");
  items.push(last);
  return items;
}

export default function Pagination({
  total,
  page,
  perPage,
  onPageChange,
  onPerPageChange,
  pageSizeOptions = [10, 25, 50],
}: PaginationProps) {
  const { t, dir } = useI18n();
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const safePage = Math.min(Math.max(1, page), lastPage);
  const from = total === 0 ? 0 : (safePage - 1) * perPage + 1;
  const to = Math.min(safePage * perPage, total);
  const items = useMemo(() => pageItems(safePage, lastPage), [safePage, lastPage]);

  const PrevIcon = dir === "rtl" ? ChevronRight : ChevronLeft;
  const NextIcon = dir === "rtl" ? ChevronLeft : ChevronRight;

  const btn =
    "inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed";
  const idle = `${btn} text-muted hover:bg-card-hover/60 hover:text-foreground`;
  const active = `${btn} bg-primary text-primary-foreground`;

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-border/50 px-4 py-3">
      <div className="flex items-center gap-3 text-xs text-muted">
        {onPerPageChange && (
          <label className="flex items-center gap-2">
            <span>{t("common.per_page")}</span>
            <select
              value={perPage}
              onChange={(e) => onPerPageChange(Number(e.target.value))}
              className="h-8 rounded-lg border border-border bg-card px-2 text-xs text-foreground outline-none focus:border-primary"
            >
              {pageSizeOptions.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}
        <span>{t("common.pagination_info", { from: String(from), to: String(to), total: String(total) })}</span>
      </div>

      <nav aria-label="pagination" className="flex items-center gap-1">
        <button
          type="button"
          className={idle}
          disabled={safePage <= 1}
          onClick={() => onPageChange(safePage - 1)}
          aria-label={t("common.previous")}
        >
          <PrevIcon className="w-4 h-4" />
        </button>
        {items.map((item, i) =>
          item === "…" ? (
            <span key={`e${i}`} className="px-1 text-xs text-muted">
              …
            </span>
          ) : (
            <button
              key={item}
              type="button"
              className={item === safePage ? active : idle}
              onClick={() => onPageChange(item)}
            >
              {item}
            </button>
          )
        )}
        <button
          type="button"
          className={idle}
          disabled={safePage >= lastPage}
          onClick={() => onPageChange(safePage + 1)}
          aria-label={t("common.next")}
        >
          <NextIcon className="w-4 h-4" />
        </button>
      </nav>
    </div>
  );
}