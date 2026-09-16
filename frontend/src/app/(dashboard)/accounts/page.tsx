"use client";

import { useEffect, useState, useCallback, useMemo } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Accounts, nextAccountCode } from "@/lib/api";
import Pagination from "@/components/ui/Pagination";
import { usePagination, paginationParams } from "@/lib/pagination";
import type { Account } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import SlideOver from "@/components/ui/SlideOver";
import StatusBadge from "@/components/ui/StatusBadge";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import EmptyState from "@/components/ui/EmptyState";
import { Calculator, Plus, Search, ChevronRight, ChevronDown, Folder, FolderOpen } from "lucide-react";
import { useI18n } from "@/lib/i18n";

const emptyForm = { code: "", name: "", type: "asset", balance: "", is_active: "true" };

const accountTypes = ["asset", "liability", "equity", "revenue", "expense"];

const INDENT = 28;

type AccountNode = Account & {
  children: AccountNode[];
  level: number;
  total: number;
  hasChildren: boolean;
};

function buildAccountTree(accounts: Account[]): AccountNode[] {
  const map = new Map<number, AccountNode>();
  for (const a of accounts) {
    map.set(a.id, { ...a, children: [], level: 0, total: a.balance ?? 0, hasChildren: false });
  }

  const roots: AccountNode[] = [];
  for (const node of map.values()) {
    const parentId = node.parent_id ?? null;
    const parent = parentId != null ? map.get(parentId) : undefined;
    if (parent) {
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }

  const sortRec = (list: AccountNode[]) => {
    list.sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true }));
    for (const n of list) {
      n.hasChildren = n.children.length > 0;
      sortRec(n.children);
    }
  };

  const calcTotal = (node: AccountNode): number => {
    node.total = (node.balance ?? 0) + node.children.reduce((sum, c) => sum + calcTotal(c), 0);
    return node.total;
  };

  const setLevels = (list: AccountNode[], level: number) => {
    for (const n of list) {
      n.level = level;
      setLevels(n.children, level + 1);
    }
  };

  sortRec(roots);
  for (const root of roots) calcTotal(root);
  setLevels(roots, 0);

  return roots;
}

function filterAccountTree(nodes: AccountNode[], query: string): AccountNode[] {
  const term = query.trim().toLowerCase();
  if (!term) return nodes;

  const matches = (n: AccountNode) =>
    n.code.toLowerCase().includes(term) ||
    n.name.toLowerCase().includes(term) ||
    String((n.metadata as Record<string, unknown> | null)?.name_ar ?? "").toLowerCase().includes(term);

  const prune = (list: AccountNode[]): AccountNode[] => {
    const out: AccountNode[] = [];
    for (const n of list) {
      const kids = prune(n.children);
      if (matches(n) || kids.length) {
        out.push({ ...n, children: kids });
      }
    }
    return out;
  };

  return prune(nodes);
}

function flattenAccountTree(nodes: AccountNode[], collapsed: ReadonlySet<number>): AccountNode[] {
  const out: AccountNode[] = [];
  const walk = (list: AccountNode[]) => {
    for (const n of list) {
      out.push(n);
      if (n.hasChildren && !collapsed.has(n.id)) walk(n.children);
    }
  };
  walk(nodes);
  return out;
}

function collectParentIds(nodes: AccountNode[]): number[] {
  return nodes.flatMap((n) => (n.hasChildren ? [n.id, ...collectParentIds(n.children)] : []));
}

export default function AccountsPage() {
  const { t, locale } = useI18n();
  const { token, business } = useAuthStore();
  const [data, setData] = useState<Account[]>([]);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
  const [slideOpen, setSlideOpen] = useState(false);
  const [editing, setEditing] = useState<Account | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [suggestedCode, setSuggestedCode] = useState("");
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState("");

  const tree = useMemo(() => filterAccountTree(buildAccountTree(data), search), [data, search]);
  const rows = useMemo(() => flattenAccountTree(tree, collapsed), [tree, collapsed]);

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    Accounts.list(token, business.id, paginationParams(page, perPage) as Record<string, string | number>)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 0);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const suggestCode = useCallback(
    (type: string) => {
      if (!token || !business) return;
      nextAccountCode(token, business.id, type)
        .then(({ code }) => {
          setSuggestedCode(code);
          setForm((p) => ({ ...p, code }));
        })
        .catch(() => {});
    },
    [token, business],
  );

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setSuggestedCode("");
    setDeleteError("");
    setSlideOpen(true);
    suggestCode("asset");
  };

  const openEdit = (a: Account) => {
    setEditing(a);
    setForm({
      code: a.code,
      name: a.name,
      type: a.type,
      balance: String(a.balance ?? 0),
      is_active: String(a.is_active),
    });
    setDeleteError("");
    setSlideOpen(true);
  };

  const toggleNode = (id: number) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const expandAll = () => setCollapsed(new Set());
  const collapseAll = () => setCollapsed(new Set(collectParentIds(tree)));

  const handleDelete = async () => {
    if (!token || !business || !editing) return;
    setDeleting(true);
    setDeleteError("");
    try {
      await Accounts.delete(token, business.id, editing.id);
      setSlideOpen(false);
      setDeleteConfirmOpen(false);
      setEditing(null);
      fetchData();
    } catch (e) {
      setDeleteError(e instanceof Error ? e.message : t("accounts.delete_failed"));
    } finally {
      setDeleting(false);
    }
  };

  const handleSave = async () => {
    if (!token || !business || !form.name || !form.code) return;
    setSaving(true);
    try {
      const payload = {
        ...form,
        opening_balance: Number(form.balance),
        is_active: form.is_active === "true",
      };
      if (editing) {
        await Accounts.update(token, business.id, editing.id, payload);
      } else {
        await Accounts.create(token, business.id, payload);
      }
      setSlideOpen(false);
      fetchData();
    } catch {
    } finally {
      setSaving(false);
    }
  };

  const displayName = (node: AccountNode) => {
    const nameAr = (node.metadata as Record<string, unknown> | null)?.name_ar as string | undefined;
    return nameAr && locale === "ar" ? nameAr : node.name;
  };

  const renderName = (node: AccountNode) => (
    <div
      className="flex items-center gap-2 min-w-0"
      style={{ paddingInlineStart: node.level * INDENT }}
      title={node.name}
    >
      <button
        type="button"
        tabIndex={node.hasChildren ? 0 : -1}
        aria-label={node.hasChildren ? (collapsed.has(node.id) ? "expand" : "collapse") : undefined}
        onClick={(e) => {
          e.stopPropagation();
          toggleNode(node.id);
        }}
        className={`flex items-center justify-center w-6 h-6 shrink-0 rounded-md transition-colors ${
          node.hasChildren ? "hover:bg-card-hover text-muted" : "pointer-events-none"
        }`}
      >
        {node.hasChildren ? (
          collapsed.has(node.id) ? (
            <ChevronRight className="w-4 h-4" />
          ) : (
            <ChevronDown className="w-4 h-4" />
          )
        ) : (
          <span className="w-4 h-4" />
        )}
      </button>
      {node.hasChildren ? (
        collapsed.has(node.id) ? (
          <Folder className="w-4 h-4 text-amber-400/80 shrink-0" />
        ) : (
          <FolderOpen className="w-4 h-4 text-amber-400 shrink-0" />
        )
      ) : (
        <span className="w-4 h-4 shrink-0" />
      )}
      <span className={`truncate ${node.hasChildren ? "font-semibold" : ""}`}>{displayName(node)}</span>
      <span
        className={`shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide border ${
          node.hasChildren
            ? "bg-amber-500/10 text-amber-400 border-amber-500/30"
            : "bg-muted/10 text-muted border-border/30"
        }`}
      >
        {node.hasChildren ? t("accounts.header") : t("accounts.sub")}
      </span>
    </div>
  );

  const renderBalance = (node: AccountNode) => (
    <span className={`tabular-nums whitespace-nowrap ${node.hasChildren ? "font-semibold" : ""}`}>
      {formatCurrency(node.hasChildren ? node.total : node.balance, locale)}
    </span>
  );

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      <PageHeader
        title={t("accounts.title")}
        subtitle={t("accounts.subtitle", { count: String(total) })}
        action={
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("accounts.add")}
          </button>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="relative max-w-sm flex-1 min-w-[220px]">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
          <input
            type="text"
            placeholder={t("accounts.search")}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              resetPage();
            }}
            className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
          />
        </div>
        {tree.some((n) => n.hasChildren) && (
          <div className="flex gap-2">
            <button
              onClick={expandAll}
              className="px-3 py-2 bg-card/80 border border-border rounded-xl text-xs font-medium text-muted hover:text-foreground hover:border-border-hover transition-colors"
            >
              {t("accounts.expand_all")}
            </button>
            <button
              onClick={collapseAll}
              className="px-3 py-2 bg-card/80 border border-border rounded-xl text-xs font-medium text-muted hover:text-foreground hover:border-border-hover transition-colors"
            >
              {t("accounts.collapse_all")}
            </button>
          </div>
        )}
      </div>

      {loading ? (
        <div className="glass rounded-2xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {["code", "name", "type", "balance", "active"].map((k) => (
                    <th key={k} className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">
                      {k}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i} className="border-b border-border/50">
                    {Array.from({ length: 5 }).map((__, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 skeleton rounded w-3/4" />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : rows.length === 0 ? (
        <div className="glass rounded-2xl overflow-hidden">
          <EmptyState icon={Calculator} title={t("accounts.empty")} />
        </div>
      ) : (
        <div className="glass rounded-2xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("common.code")}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("common.name")}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("common.type")}</th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-muted uppercase tracking-wider">{t("common.balance")}</th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-muted uppercase tracking-wider">{t("common.active")}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((node, idx) => (
                  <tr
                    key={node.id}
                    onClick={() => openEdit(node)}
                    className={`border-b border-border/30 transition-colors hover:bg-card-hover/30 cursor-pointer ${
                      node.hasChildren ? "bg-card/40" : idx % 2 === 1 ? "bg-card/20" : ""
                    }`}
                  >
                    <td className="px-4 py-3 text-sm text-muted font-mono whitespace-nowrap">{node.code}</td>
                    <td className="px-4 py-3 text-sm text-foreground">{renderName(node)}</td>
                    <td className="px-4 py-3 text-sm text-foreground">
                      <StatusBadge status={node.type} />
                    </td>
                    <td className="px-4 py-3 text-sm text-end text-foreground">{renderBalance(node)}</td>
                    <td className="px-4 py-3 text-sm text-foreground">
                      <span className={node.is_active ? "text-emerald-400" : "text-muted"}>
                        {node.is_active ? t("common.yes") : t("common.no")}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination total={total} page={page} perPage={perPage} onPageChange={setPage} onPerPageChange={changePageSize} />
        </div>
      )}

      <SlideOver open={slideOpen} onClose={() => setSlideOpen(false)} title={editing ? t("accounts.edit") : t("accounts.create")}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.code")}</label>
            <div className="flex gap-2">
              <input
                type="text"
                value={form.code}
                onChange={(e) => setForm((p) => ({ ...p, code: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
                placeholder={t("accounts.code_placeholder")}
              />
              {!editing && (
                <button
                  type="button"
                  onClick={() => suggestCode(form.type)}
                  className="shrink-0 px-3 py-2.5 bg-card border border-border rounded-xl text-sm text-muted hover:text-foreground hover:border-border-hover transition-colors"
                >
                  {t("accounts.suggest_code")}
                </button>
              )}
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.name")}</label>
            <input
              type="text"
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.type")}</label>
            <select
              value={form.type}
              onChange={(e) => {
                const type = e.target.value;
                setForm((p) => ({ ...p, type }));
                if (!editing && (!form.code || form.code === suggestedCode)) {
                  suggestCode(type);
                }
              }}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              {accountTypes.map((tv) => (
                <option key={tv} value={tv}>{tv.charAt(0).toUpperCase() + tv.slice(1)}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{editing ? t("accounts.balance_jod") : t("accounts.opening_balance")}</label>
            <input
              type="number"
              step="0.01"
              value={form.balance}
              onChange={(e) => setForm((p) => ({ ...p, balance: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
            {!editing && <p className="mt-1 text-xs text-muted">{t("accounts.opening_balance_hint")}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium text-muted mb-1.5">{t("common.active")}</label>
            <select
              value={form.is_active}
              onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors"
            >
              <option value="true">{t("common.yes")}</option>
              <option value="false">{t("common.no")}</option>
            </select>
          </div>
          <div className="flex gap-3">
            {editing && (
              <button
                type="button"
                onClick={() => setDeleteConfirmOpen(true)}
                className="flex-1 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors"
              >
                {t("common.delete")}
              </button>
            )}
            <button
              onClick={handleSave}
              disabled={saving || !form.name || !form.code}
              className={`py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50 ${editing ? "flex-1" : "w-full"}`}
            >
              {saving ? t("common.saving") : editing ? t("accounts.save") : t("accounts.create_btn")}
            </button>
          </div>
        </div>
      </SlideOver>

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={handleDelete}
        title={t("accounts.delete_confirm_title")}
        message={`${t("accounts.delete_confirm_message")} ${editing ? `"${editing.name}"` : ""}`}
        confirmLabel={t("common.delete")}
        loading={deleting}
        error={deleteError}
      />
    </motion.div>
  );
}
