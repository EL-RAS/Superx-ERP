"use client";
import { ReactNode } from "react";
import { formatCellValue } from "@/lib/morphing-engine";
import { useI18n } from "@/lib/i18n";
import EmptyState from "./EmptyState";
import Pagination from "./Pagination";

export interface Column {
  key: string;
  label: string;
  type?: "text" | "currency" | "number" | "date" | "badge" | "boolean";
  className?: string;
  render?: (value: unknown, row: Record<string, unknown>) => ReactNode;
}

interface PaginationPropsOptional {
  total?: number;
  page?: number;
  perPage?: number;
  onPageChange?: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
}

interface DataTableProps {
  columns: Column[];
  data: Record<string, unknown>[];
  loading?: boolean;
  emptyMessage?: string;
  emptyIcon?: React.ComponentType<{ className?: string }>;
  onRowClick?: (row: Record<string, unknown>) => void;
  rowKey?: string;
  rowClassName?: (row: Record<string, unknown>) => string;
  pagination?: PaginationPropsOptional;
}

function SkeletonRow({ cols }: { cols: number }) {
  return (
    <tr className="border-b border-border/50">
      {Array.from({ length: cols }).map((_, i) => (
        <td key={i} className="px-4 py-3">
          <div className="h-4 skeleton rounded w-3/4" />
        </td>
      ))}
    </tr>
  );
}

export default function DataTable({ columns, data, loading, emptyMessage, emptyIcon, onRowClick, rowKey = "id", rowClassName, pagination }: DataTableProps) {
  const { locale, t } = useI18n();
  const total = pagination?.total ?? 0;
  const page = pagination?.page ?? 1;
  const perPage = pagination?.perPage ?? 10;
  const showPagination = !!pagination && total > 0;
  if (loading) {
    return (
      <div className="glass rounded-2xl overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border">
                {columns.map((col) => (
                  <th key={col.key} className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {Array.from({ length: 5 }).map((_, i) => (
                <SkeletonRow key={i} cols={columns.length} />
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  if (!data || data.length === 0) {
    return (
      <div className="glass rounded-2xl overflow-hidden">
        <EmptyState icon={emptyIcon} title={emptyMessage ?? t("common.no_data")} />
      </div>
    );
  }

  return (
    <div className="glass rounded-2xl overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="border-b border-border">
              {columns.map((col) => (
                <th key={col.key} className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((row, idx) => (
              <tr
                key={String(row[rowKey] ?? idx)}
                onClick={() => onRowClick?.(row)}
                className={`border-b border-border/30 transition-colors hover:bg-card-hover/30 ${onRowClick ? "cursor-pointer" : ""} ${idx % 2 === 1 ? "bg-card/20" : ""} ${rowClassName?.(row) ?? ""}`}
              >
                {columns.map((col) => (
                  <td key={col.key} className={`px-4 py-3 text-sm text-foreground ${col.className || ""}`}>
                    {col.render
                      ? col.render(row[col.key], row)
                      : formatCellValue(row[col.key], col.type || "text", locale)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {showPagination && pagination?.onPageChange && (
        <Pagination
          total={total}
          page={page}
          perPage={perPage}
          onPageChange={pagination.onPageChange}
          onPerPageChange={pagination.onPerPageChange}
        />
      )}
    </div>
  );
}
