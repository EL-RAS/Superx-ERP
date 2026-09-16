"use client";
import { useCallback, useState } from "react";

export interface PaginationState {
  page: number;
  perPage: number;
}

export function usePagination(initialPerPage = 10): {
  page: number;
  perPage: number;
  setPage: (p: number) => void;
  resetPage: () => void;
  changePageSize: (n: number) => void;
} {
  const [page, setPageState] = useState(1);
  const [perPage, setPerPage] = useState(initialPerPage);

  const setPage = useCallback((p: number) => setPageState(Math.max(1, p)), []);
  const resetPage = useCallback(() => setPageState(1), []);
  const changePageSize = useCallback((n: number) => {
    setPerPage(n);
    setPageState(1);
  }, []);

  return { page, perPage, setPage, resetPage, changePageSize };
}

export function paginationParams(
  page: number,
  perPage: number,
  extra?: Record<string, unknown>
): { page: number; per_page: number } & Record<string, unknown> {
  return { ...(extra ?? {}), page, per_page: perPage };
}