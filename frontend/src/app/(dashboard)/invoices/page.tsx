"use client";

import { useEffect, useState, useCallback } from "react";
import { motion } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import { Invoices, Customers, Products as ProductsApi, payInvoice, voidInvoice, duplicateInvoice, updateInvoiceStatus } from "@/lib/api";
import type { Invoice, Customer, Product } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import PageHeader from "@/components/ui/PageHeader";
import DataTable from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import SlideOver from "@/components/ui/SlideOver";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import StatusBadge from "@/components/ui/StatusBadge";
import PaymentModal from "@/components/invoicing/PaymentModal";
import { useI18n } from "@/lib/i18n";
import { quantityStep } from "@/lib/product";
import { usePagination } from "@/lib/pagination";
import { hasPermission } from "@/lib/rbac";
import { round2 } from "@/lib/math";
import { FileText, Plus, X, Copy, Send, Ban, CreditCard, Loader2, Search } from "lucide-react";

const statusTabs = ["all", "paid", "unpaid", "partial", "void"] as const;

interface InvoiceFormData {
  customer_id: string;
  due_date: string;
  notes: string;
  shipping_amount: string;
  form_status: string;
  items: { product_id: string; name: string; quantity: string; unit_price: string; discount: string; tax_rate: string; is_weighable?: boolean; unit?: string | null }[];
}

const emptyItem = { product_id: "", name: "", quantity: "1", unit_price: "0", discount: "0", tax_rate: "0", is_weighable: false, unit: null };

export default function InvoicesPage() {
  const { token, business, config } = useAuthStore();
  const { t, locale } = useI18n();
  const footerTerms = (config?.settings as Record<string, unknown>)?.invoice_footer_terms as string | undefined;
  // Existing invoices are strictly locked: only admins (or users explicitly
  // granted `sales.lock`) may edit, mark sent, void, or delete them.
  const canLock = hasPermission(config, "sales.lock");
  const canDelete = hasPermission(config, "sales.delete");

  const columns: Column[] = [
    { key: "invoice_number", label: t("invoices.invoice_num") },
    {
      key: "customer",
      label: t("invoices.customer"),
      render: (_v, row) => ((row as unknown as Invoice).customer?.name ?? t("invoices.walk_in")) as React.ReactNode,
    },
    { key: "net_amount", label: t("common.amount"), type: "currency" },
    {
      key: "payment_status",
      label: t("common.status"),
      render: (v) => <StatusBadge status={String(v)} />,
    },
    { key: "created_at", label: t("common.date"), type: "date" },
  ];

  const [data, setData] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const [formOpen, setFormOpen] = useState(false);
  const [editingInvoice, setEditingInvoice] = useState<Invoice | null>(null);
  const [formLoading, setFormLoading] = useState(false);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [form, setForm] = useState<InvoiceFormData>({
    customer_id: "", due_date: "", notes: "", shipping_amount: "0", form_status: "unpaid",
    items: [{ ...emptyItem }],
  });

  const [paymentOpen, setPaymentOpen] = useState(false);
  const [paymentLoading, setPaymentLoading] = useState(false);
  const [products, setProducts] = useState<Product[]>([]);
  const [productSearch, setProductSearch] = useState("");
  const [searchFocused, setSearchFocused] = useState<number | null>(null);

  const [toast, setToast] = useState<{ msg: string; type: "success" | "error" } | null>(null);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [total, setTotal] = useState(0);
  const { page, perPage, setPage, resetPage, changePageSize } = usePagination();

  const showToast = (msg: string, type: "success" | "error" = "success") => {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3000);
  };

  const fetchData = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (statusFilter === "void") {
      params.status = "void";
    } else if (statusFilter !== "all") {
      params.payment_status = statusFilter;
    }
    Invoices.list(token, business.id, params)
      .then((res) => {
        setData(res.data);
        setTotal(res.total);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [token, business, statusFilter, page, perPage]);

  useEffect(() => {
    const timer = setTimeout(fetchData, 300);
    return () => clearTimeout(timer);
  }, [fetchData]);

  const fetchCustomers = useCallback(() => {
    if (!token || !business) return;
    Customers.list(token, business.id, { per_page: 200 })
      .then((res) => setCustomers(res.data))
      .catch(() => {});
  }, [token, business]);

  const fetchProducts = useCallback(() => {
    if (!token || !business) return;
    ProductsApi.list(token, business.id, { per_page: 200 })
      .then((res) => setProducts(res.data))
      .catch(() => {});
  }, [token, business]);

  const openDetail = async (row: Record<string, unknown>) => {
    if (!token || !business) return;
    const inv = row as unknown as Invoice;
    setDetailLoading(true);
    setSelectedInvoice(inv);
    try {
      const full = await Invoices.get(token, business.id, inv.id);
      setSelectedInvoice(full);
    } catch {} finally {
      setDetailLoading(false);
    }
  };

  const openCreateForm = () => {
    fetchCustomers();
    fetchProducts();
    setEditingInvoice(null);
    setProductSearch("");
    setForm({ customer_id: "", due_date: "", notes: "", shipping_amount: "0", form_status: "unpaid", items: [{ ...emptyItem }] });
    setFormOpen(true);
  };

  const openEditForm = (inv: Invoice) => {
    fetchCustomers();
    fetchProducts();
    setEditingInvoice(inv);
    setProductSearch("");
    setForm({
      customer_id: inv.customer_id ? String(inv.customer_id) : "",
      due_date: inv.due_date ? inv.due_date.split("T")[0] : "",
      notes: inv.notes ?? "",
      shipping_amount: String(inv.shipping_amount ?? 0),
      form_status: inv.payment_status ?? "unpaid",
      items: inv.items && inv.items.length > 0
        ? inv.items.map((it) => ({
            product_id: it.product_id ? String(it.product_id) : "",
            name: it.name,
            quantity: String(it.quantity),
            unit_price: String(it.unit_price),
            discount: String(it.discount),
            tax_rate: String(it.tax_rate),
          }))
        : [{ ...emptyItem }],
    });
    setFormOpen(true);
  };

  const updateFormItem = (idx: number, field: string, value: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it)),
    }));
  };

  const addItem = () => {
    setForm((prev) => ({ ...prev, items: [...prev.items, { ...emptyItem }] }));
    setSearchFocused(null);
  };

  const removeItem = (idx: number) => setForm((prev) => ({ ...prev, items: prev.items.filter((_, i) => i !== idx) }));

  const itemStep = (it: InvoiceFormData["items"][number]) =>
    quantityStep(it.is_weighable !== undefined ? { is_weighable: it.is_weighable, unit: it.unit } : products.find((p) => String(p.id) === it.product_id));

  const calcFormTotals = () => {
    let subtotal = 0, totalTax = 0, totalDiscount = 0;
    form.items.forEach((it) => {
      const qty = parseFloat(it.quantity) || 0;
      const price = parseFloat(it.unit_price) || 0;
      const disc = parseFloat(it.discount) || 0;
      const tax = parseFloat(it.tax_rate) || 0;
      const lineTotal = qty * price;
      const lineDisc = disc;
      const lineTax = (lineTotal - lineDisc) * (tax / 100);
      subtotal += lineTotal;
      totalTax += lineTax;
      totalDiscount += lineDisc;
    });
    const shipping = parseFloat(form.shipping_amount) || 0;
    const net = subtotal + totalTax - totalDiscount + shipping;
    return { subtotal: round2(subtotal), totalTax: round2(totalTax), totalDiscount: round2(totalDiscount), net: round2(net) };
  };

  const handleSave = async () => {
    if (!token || !business) return;
    const validItems = form.items.filter((it) => it.name.trim() && parseFloat(it.quantity) > 0);
    if (validItems.length === 0) { showToast(t("invoices.error_items_required"), "error"); return; }
    setFormLoading(true);
    try {
      const payload = {
        customer_id: form.customer_id ? parseInt(form.customer_id) : null,
        due_date: form.due_date || null,
        notes: form.notes || null,
        shipping_amount: parseFloat(form.shipping_amount) || 0,
        payment_status: form.form_status || "unpaid",
        items: validItems.map((it) => ({
          product_id: it.product_id ? parseInt(it.product_id) : null,
          name: it.name,
          quantity: parseFloat(it.quantity),
          unit_price: parseFloat(it.unit_price),
          discount: parseFloat(it.discount) || 0,
          tax_rate: parseFloat(it.tax_rate) || 0,
        })),
      };
      if (editingInvoice) {
        await Invoices.update(token, business.id, editingInvoice.id, payload);
        showToast(t("invoices.success_updated"));
      } else {
        await Invoices.create(token, business.id, payload);
        showToast(t("invoices.success_created"));
      }
      setFormOpen(false);
      setSelectedInvoice(null);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally {
      setFormLoading(false);
    }
  };

  const handlePay = async (data: { amount: number; method: string; reference_number?: string; notes?: string }) => {
    if (!token || !business || !selectedInvoice) return;
    setPaymentLoading(true);
    try {
      await payInvoice(token, business.id, selectedInvoice.id, data);
      showToast(t("invoices.success_payment"));
      setPaymentOpen(false);
      const full = await Invoices.get(token, business.id, selectedInvoice.id);
      setSelectedInvoice(full);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally {
      setPaymentLoading(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !business || !selectedInvoice) return;
    setDeleting(true);
    try {
      await Invoices.delete(token, business.id, selectedInvoice.id);
      showToast(t("invoices.success_deleted"));
      setSelectedInvoice(null);
      setDeleteConfirmOpen(false);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    } finally {
      setDeleting(false);
    }
  };

  const handleVoid = async (inv: Invoice) => {
    if (!token || !business) return;
    if (!confirm(t("invoices.confirm_void"))) return;
    try {
      await voidInvoice(token, business.id, inv.id);
      showToast(t("invoices.success_voided"));
      setSelectedInvoice(null);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const handleDuplicate = async (inv: Invoice) => {
    if (!token || !business) return;
    try {
      const newInv = await duplicateInvoice(token, business.id, inv.id);
      showToast(t("invoices.success_created"));
      setSelectedInvoice(null);
      fetchData();
      openEditForm(newInv);
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const handleMarkSent = async (inv: Invoice) => {
    if (!token || !business) return;
    try {
      await Invoices.patch(token, business.id, inv.id, { status: "sent" });
      const full = await Invoices.get(token, business.id, inv.id);
      setSelectedInvoice(full);
      fetchData();
    } catch {} 
  };

  const handleStatusChange = async (id: number | string, newStatus: string) => {
    if (!token || !business) return;
    try {
      if (newStatus === "void") {
        await voidInvoice(token, business.id, id);
      } else {
        await updateInvoiceStatus(token, business.id, id, newStatus);
      }
      const updated = await Invoices.get(token, business.id, id);
      setSelectedInvoice(updated);
      fetchData();
    } catch (e: unknown) {
      showToast(e instanceof Error ? e.message : t("common.error"), "error");
    }
  };

  const selectProduct = (idx: number, p: Product) => {
    updateFormItem(idx, "product_id", String(p.id));
    updateFormItem(idx, "name", p.name);
    updateFormItem(idx, "unit_price", String(p.price ?? 0));
    updateFormItem(idx, "tax_rate", String(p.tax_rate ?? 0));
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((it, i) => (i === idx ? { ...it, is_weighable: !!p.is_weighable, unit: p.unit ?? null } : it)),
    }));
    setSearchFocused(null);
    setProductSearch("");
  };

  const { subtotal, totalTax, totalDiscount, net } = calcFormTotals();

  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
      {toast && (
        <div className={`fixed top-4 end-4 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg transition-all ${
          toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "bg-red-500/20 text-red-400 border border-red-500/30"
        }`}>{toast.msg}</div>
      )}

      <PageHeader
        title={t("invoices.title")}
        subtitle={t("invoices.subtitle", { count: String(data.length) })}
        action={
          <button onClick={openCreateForm} className="flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
            <Plus className="w-4 h-4" /> {t("invoices.new")}
          </button>
        }
      />

      <div className="flex gap-2 mb-4">
        {statusTabs.map((tab) => (
          <button key={tab} onClick={() => { setStatusFilter(tab); resetPage(); }}
            className={`px-4 py-2 rounded-xl text-sm font-medium transition-colors ${
              statusFilter === tab ? "bg-primary/20 text-primary-light border border-primary/30" : "text-muted hover:text-foreground hover:bg-card-hover"
            }`}>
            {tab === "all" ? t("common.all") : t(`status.${tab}`)}
          </button>
        ))}
      </div>

      <DataTable columns={columns} data={data as unknown as Record<string, unknown>[]} loading={loading} emptyMessage={t("invoices.empty")} emptyIcon={FileText} onRowClick={openDetail} pagination={{ total, page, perPage, onPageChange: setPage, onPerPageChange: changePageSize }} />

      {/* Detail SlideOver */}
      <SlideOver open={!!selectedInvoice && !formOpen} onClose={() => setSelectedInvoice(null)} title={t("invoices.detail")} width="max-w-xl">
        {selectedInvoice && (
          <div className="space-y-6">
            <div className="glass rounded-xl p-4 space-y-3">
              <div className="flex justify-between"><span className="text-sm text-muted">{t("invoices.invoice_num")}</span><span className="text-sm text-foreground font-medium">{selectedInvoice.invoice_number}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("invoices.customer")}</span><span className="text-sm text-foreground">{selectedInvoice.customer?.name ?? t("invoices.walk_in")}</span></div>
              <div className="flex justify-between"><span className="text-sm text-muted">{t("common.date")}</span><span className="text-sm text-foreground">{new Date(selectedInvoice.created_at).toLocaleDateString("en-JO")}</span></div>
              <div className="flex justify-between items-center"><span className="text-sm text-muted">{t("common.status")}</span><StatusBadge status={selectedInvoice.payment_status} /></div>
              {selectedInvoice.due_date && <div className="flex justify-between"><span className="text-sm text-muted">{t("invoices.due_date")}</span><span className="text-sm text-foreground">{new Date(selectedInvoice.due_date).toLocaleDateString()}</span></div>}
              {selectedInvoice.notes && <div className="flex justify-between"><span className="text-sm text-muted">{t("invoices.notes")}</span><span className="text-sm text-foreground">{selectedInvoice.notes}</span></div>}
              {footerTerms && <div className="border-t border-border pt-2 text-xs text-muted whitespace-pre-wrap">{footerTerms}</div>}
            </div>

            {/* Actions */}
            <div className="flex flex-wrap gap-2">
              {selectedInvoice.payment_status !== "paid" && selectedInvoice.status !== "void" && (
                <button onClick={() => handleStatusChange(selectedInvoice.id, "paid")} className="flex items-center gap-1.5 px-3 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-xl text-xs font-medium hover:bg-emerald-500/30 transition-colors">
                  <CreditCard className="w-3.5 h-3.5" /> {t("invoices.mark_paid")}
                </button>
              )}
              {selectedInvoice.payment_status !== "unpaid" && selectedInvoice.status !== "void" && (
                <button onClick={() => handleStatusChange(selectedInvoice.id, "unpaid")} className="flex items-center gap-1.5 px-3 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-xl text-xs font-medium hover:bg-amber-500/30 transition-colors">
                  {t("invoices.mark_unpaid")}
                </button>
              )}
              {canLock && selectedInvoice.status === "draft" && (
                <button onClick={() => handleMarkSent(selectedInvoice)} className="flex items-center gap-1.5 px-3 py-2 bg-blue-500/20 text-blue-400 border border-blue-500/30 rounded-xl text-xs font-medium hover:bg-blue-500/30 transition-colors">
                  <Send className="w-3.5 h-3.5" /> {t("invoices.mark_sent")}
                </button>
              )}
              {canLock && (
                <button onClick={() => { openEditForm(selectedInvoice); }} className="flex items-center gap-1.5 px-3 py-2 bg-primary/20 text-primary-light border border-primary/30 rounded-xl text-xs font-medium hover:bg-primary/30 transition-colors">
                  {t("common.edit")}
                </button>
              )}
              <button onClick={() => handleDuplicate(selectedInvoice)} className="flex items-center gap-1.5 px-3 py-2 bg-card-hover border border-border rounded-xl text-xs font-medium text-muted hover:text-foreground transition-colors">
                <Copy className="w-3.5 h-3.5" /> {t("invoices.duplicate_invoice")}
              </button>
              {canLock && selectedInvoice.status !== "void" && (
                <button onClick={() => handleVoid(selectedInvoice)} className="flex items-center gap-1.5 px-3 py-2 bg-red-500/20 text-red-400 border border-red-500/30 rounded-xl text-xs font-medium hover:bg-red-500/30 transition-colors">
                  <Ban className="w-3.5 h-3.5" /> {t("invoices.void_invoice")}
                </button>
              )}
              {canDelete && (
                <button onClick={() => setDeleteConfirmOpen(true)} className="flex items-center gap-1.5 px-3 py-2 bg-red-500/10 text-red-400/70 border border-red-500/20 rounded-xl text-xs font-medium hover:bg-red-500/20 hover:text-red-400 transition-colors">
                  {t("common.delete")}
                </button>
              )}
            </div>
            {selectedInvoice.status === "void" && <p className="text-sm text-red-400">{t("invoices.voided")}</p>}

            <div>
              <h4 className="text-sm font-medium text-muted mb-3">{t("invoices.items")}</h4>
              {selectedInvoice.items && selectedInvoice.items.length > 0 ? (
                <div className="space-y-2">
                  {selectedInvoice.items.map((item) => (
                    <div key={item.id} className="flex justify-between items-center py-2 border-b border-border/50 last:border-0">
                      <div>
                        <p className="text-sm text-foreground">{item.name}</p>
                        <p className="text-xs text-muted">{item.quantity} x {formatCurrency(item.unit_price, locale)}</p>
                      </div>
                      <p className="text-sm font-medium text-foreground">{formatCurrency(item.total, locale)}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted">{detailLoading ? t("common.loading") : t("common.no_items")}</p>
              )}
            </div>

            <div className="glass rounded-xl p-4 space-y-2">
              <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.subtotal")}</span><span className="text-foreground">{formatCurrency(selectedInvoice.subtotal ?? selectedInvoice.total_amount, locale)}</span></div>
              <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.tax")}</span><span className="text-foreground">{formatCurrency(selectedInvoice.tax_amount, locale)}</span></div>
              <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.discount")}</span><span className="text-foreground">-{formatCurrency(selectedInvoice.discount_amount, locale)}</span></div>
              {(selectedInvoice.shipping_amount ?? 0) > 0 && <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.shipping")}</span><span className="text-foreground">{formatCurrency(selectedInvoice.shipping_amount, locale)}</span></div>}
              <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50"><span className="text-foreground">{t("invoices.total")}</span><span className="text-foreground">{formatCurrency(selectedInvoice.net_amount, locale)}</span></div>
            </div>

            {/* Payments List */}
            {selectedInvoice.payments && selectedInvoice.payments.length > 0 && (
              <div>
                <h4 className="text-sm font-medium text-muted mb-3">{t("invoices.payments")}</h4>
                <div className="space-y-2">
                  {selectedInvoice.payments.map((p) => (
                    <div key={p.id} className="flex justify-between items-center py-2 border-b border-border/50 last:border-0">
                      <div>
                        <p className="text-sm text-foreground">{p.payment_number}</p>
                        <p className="text-xs text-muted">{t(`invoices.${p.method}`)}</p>
                      </div>
                      <div className="text-end">
                        <p className="text-sm font-medium text-foreground">{formatCurrency(p.amount, locale)}</p>
                        <StatusBadge status={p.status} />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </SlideOver>

      {/* Create/Edit SlideOver */}
      <SlideOver open={formOpen} onClose={() => setFormOpen(false)} title={editingInvoice ? t("invoices.edit") : t("invoices.new")} width="max-w-2xl">
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-sm text-muted mb-1 block">{t("invoices.customer")}</label>
              <select value={form.customer_id} onChange={(e) => setForm((p) => ({ ...p, customer_id: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
                <option value="">{t("invoices.walk_in")}</option>
                {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div>
              <label className="text-sm text-muted mb-1 block">{t("invoices.due_date")}</label>
              <input type="date" value={form.due_date} onChange={(e) => setForm((p) => ({ ...p, due_date: e.target.value }))}
                className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
            </div>
          </div>

          <div>
            <label className="text-sm text-muted mb-1 block">{t("invoices.notes")}</label>
            <textarea value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} rows={2}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors resize-none" />
          </div>

          <div>
            <label className="text-sm text-muted mb-1 block">{t("common.status")}</label>
            <select value={form.form_status} onChange={(e) => setForm((p) => ({ ...p, form_status: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors">
              <option value="unpaid">{t("status.unpaid")}</option>
              <option value="paid">{t("status.paid")}</option>
              <option value="partial">{t("status.partial")}</option>
            </select>
          </div>

          <div>
            <label className="text-sm text-muted mb-1 block">{t("invoices.shipping")}</label>
            <input type="number" step="0.001" min="0" value={form.shipping_amount} onChange={(e) => setForm((p) => ({ ...p, shipping_amount: e.target.value }))}
              className="w-full px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover transition-colors" />
          </div>

          <div>
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-medium text-muted">{t("invoices.items")}</h4>
              <button onClick={addItem} className="flex items-center gap-1 text-xs text-primary-light hover:text-primary transition-colors">
                <Plus className="w-3.5 h-3.5" /> {t("invoices.add_item")}
              </button>
            </div>
            <div>
              {/* Header row */}
              <div className="grid text-xs text-muted font-medium mb-1.5" style={{ gridTemplateColumns: "2.5fr 1fr 1.2fr 1fr 1fr 1.3fr 24px", gap: "6px" }}>
                <span>{t("common.product")}</span>
                <span className="text-center">{t("invoices.qty")}</span>
                <span className="text-end">{t("common.price")}</span>
                <span className="text-end">{t("invoices.tax_rate")}</span>
                <span className="text-end">{t("invoices.disc")}</span>
                <span className="text-end">{t("invoices.total")}</span>
                <span></span>
              </div>
              <div className="space-y-1.5">
                {form.items.map((item, idx) => (
                  <div key={idx} className="glass rounded-lg px-2.5 py-2">
                    <div className="grid" style={{ gridTemplateColumns: "2.5fr 1fr 1.2fr 1fr 1fr 1.3fr 24px", gap: "6px", alignItems: "start" }}>
                      <div className="relative min-w-0">
                        <input
                          placeholder={t("invoices.search_product")}
                          value={productSearch || item.name}
                          onChange={(e) => {
                            setProductSearch(e.target.value);
                            if (!e.target.value) {
                              updateFormItem(idx, "product_id", "");
                              updateFormItem(idx, "name", "");
                              updateFormItem(idx, "unit_price", "0");
                              updateFormItem(idx, "tax_rate", "0");
                            }
                          }}
                          onFocus={() => setSearchFocused(idx)}
                          onBlur={() => setTimeout(() => setSearchFocused(null), 200)}
                          className="w-full px-2 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover"
                        />
                        {searchFocused === idx && (productSearch || item.name) && (
                          <div className="absolute z-50 top-full start-0 end-0 mt-1 bg-card border border-border rounded-lg shadow-xl max-h-40 overflow-y-auto">
                            {products
                              .filter((p) => p.name.toLowerCase().includes((productSearch || item.name).toLowerCase()))
                              .slice(0, 6)
                              .map((p) => (
                                <button key={p.id} type="button"
                                  onMouseDown={() => selectProduct(idx, p)}
                                  className="w-full text-start px-2 py-1.5 text-xs text-foreground hover:bg-primary/10 transition-colors flex items-center gap-2"
                                >
                                  <Search className="w-3 h-3 text-muted shrink-0" />
                                  <span className="truncate">{p.name}</span>
                                  <span className="ms-auto text-xs text-muted shrink-0">{formatCurrency(p.price ?? 0, locale)}</span>
                                </button>
                              ))}
                          </div>
                        )}
                      </div>
                      <div className="min-w-0">
                        <input placeholder="0" type="number" min="0.01" step={itemStep(item)} value={item.quantity}
                          onChange={(e) => updateFormItem(idx, "quantity", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-center" />
                      </div>
                      <div className="min-w-0">
                        <input placeholder="0.00" type="number" min="0" step="0.001" value={item.unit_price} readOnly
                          className="w-full px-1.5 py-1.5 bg-card/40 border border-border rounded-lg text-xs text-muted cursor-not-allowed text-end" />
                      </div>
                      <div className="min-w-0">
                        <input placeholder="%" type="number" min="0" step="0.01" value={item.tax_rate}
                          onChange={(e) => updateFormItem(idx, "tax_rate", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-end" />
                      </div>
                      <div className="min-w-0">
                        <input placeholder="0" type="number" min="0" step="0.001" value={item.discount}
                          onChange={(e) => updateFormItem(idx, "discount", e.target.value)}
                          className="w-full px-1.5 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover text-end" />
                      </div>
                      <div className="flex items-center justify-end min-w-0">
                        <span className="text-xs text-foreground font-medium">{formatCurrency(
                          round2(
                            (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)
                            - (parseFloat(item.discount) || 0)
                            + ((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0) - (parseFloat(item.discount) || 0)) * ((parseFloat(item.tax_rate) || 0) / 100)
                          ),
                          "en"
                        )}</span>
                      </div>
                      <div className="flex items-center justify-center">
                        {form.items.length > 1 && (
                          <button onClick={() => removeItem(idx)} className="p-0.5 text-muted hover:text-red-400 transition-colors"><X className="w-3.5 h-3.5" /></button>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <div className="glass rounded-xl p-4 space-y-2">
            <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.subtotal")}</span><span className="text-foreground">{formatCurrency(subtotal, locale)}</span></div>
            <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.tax")}</span><span className="text-foreground">{formatCurrency(totalTax, locale)}</span></div>
            <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.discount")}</span><span className="text-foreground">-{formatCurrency(totalDiscount, locale)}</span></div>
            {parseFloat(form.shipping_amount) > 0 && <div className="flex justify-between text-sm"><span className="text-muted">{t("invoices.shipping")}</span><span className="text-foreground">{formatCurrency(parseFloat(form.shipping_amount), locale)}</span></div>}
            <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50"><span className="text-foreground">{t("invoices.total")}</span><span className="text-foreground">{formatCurrency(net, locale)}</span></div>
          </div>

          <div className="flex gap-3">
            <button onClick={handleSave} disabled={formLoading}
              className="flex items-center gap-2 px-6 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40">
              {formLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
              {formLoading ? t("invoices.saving") : t("invoices.save")}
            </button>
            <button onClick={() => setFormOpen(false)} disabled={formLoading} className="px-6 py-3 text-sm text-muted hover:text-foreground transition-colors">
              {t("common.cancel")}
            </button>
          </div>
        </div>
      </SlideOver>

      {/* Payment Modal */}
      {selectedInvoice && (
        <PaymentModal
          open={paymentOpen}
          onClose={() => setPaymentOpen(false)}
          onPay={handlePay}
          total={selectedInvoice.net_amount - (selectedInvoice.payments?.reduce((s, p) => s + (p.status === "completed" ? p.amount : 0), 0) ?? 0)}
          loading={paymentLoading}
        />
      )}

      <ConfirmDialog
        open={deleteConfirmOpen}
        onClose={() => setDeleteConfirmOpen(false)}
        onConfirm={handleDelete}
        title={t("invoices.delete_title")}
        message={t("invoices.delete_message", { invoice: selectedInvoice?.invoice_number ?? "" })}
        confirmLabel={t("common.delete")}
        loading={deleting}
      />
    </motion.div>
  );
}
