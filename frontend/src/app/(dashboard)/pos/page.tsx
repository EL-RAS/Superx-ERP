"use client";

import { useEffect, useState, useCallback, useRef, useMemo } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { useAuthStore } from "@/stores/auth-store";
import {
  Products,
  Customers,
  lookupBarcode,
  quickAddProduct,
  Invoices,
  Shifts,
  lookupCustomerByPhone,
  createLoyaltyTransaction,
  fetchCurrentShift,
  fetchLastClosedShift,
  closeShift,
  fetchZReport,
  fetchCategories,
  applyPromotions,
  ApiError,
} from "@/lib/api";
import type { Product, Customer, LoyaltyCard, Shift, Category, Invoice, PromotionApplyResult } from "@/lib/types";
import { formatCurrency } from "@/lib/types";
import { useI18n } from "@/lib/i18n";
import { isSupermarketVertical } from "@/lib/morphing-engine";
import {
  Search,
  X,
  Minus,
  Plus,
  ShoppingCart,
  CreditCard,
  Banknote,
  User,
  Check,
  Loader2,
  ScanBarcode,
  AlertTriangle,
  Gift,
  Percent,
  RotateCcw,
  Weight,
  Smartphone,
  Clock,
  ChevronDown,
  Trash2,
  Layers,
  Pause,
  Play,
  ArrowRight,
  DollarSign,
  UserPlus,
  PackagePlus,
  Printer,
} from "lucide-react";

const CART_KEY = "sx_pos_cart";
const HOLD_KEY = "sx_pos_held_cart";

interface CartItem {
  product: Product;
  quantity: number;
  weight?: number;
}

interface Toast {
  id: number;
  type: "success" | "error" | "info";
  message: string;
}

let toastCounter = 0;

// Current client local wall clock as "HH:MM" (evaluated in the browser timezone).
const localClockTime = () => {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
};

export default function POSPage() {
  const { t, locale } = useI18n();
  const { token, business, config } = useAuthStore();

  const settings = (config?.settings ?? {}) as Record<string, unknown>;
  const crmEnabled = config?.features?.crm_enabled !== false;
  const rapidMode = settings.rapid_mode === true;
  const allowSplit = settings.allow_split_payments === true;
  const loyaltyEnabled = crmEnabled && settings.loyalty_enabled === true;
  const promotionsEnabled = settings.promotions_enabled === true;
  const footerTerms = settings.invoice_footer_terms as string | undefined;
  const taxInclusive = settings.tax_calculation_method !== "exclusive";
  const loyaltyEarnRate = Number(settings.loyalty_earn_rate ?? 1);
  const loyaltyRedemptionRate = Number(settings.loyalty_redemption_rate ?? 100);
  const loyaltyAlertThreshold = Number(settings.loyalty_alert_threshold ?? 500);
  const loyaltyRedemptionJod = 1 / loyaltyRedemptionRate;
  const autoPrintReceipt = settings.auto_print_receipt === true;
  const receiptPaperWidth = (settings.receipt_paper_width as string) === "58mm" ? "58mm" : "80mm";
  const receiptFooterMessage = settings.receipt_footer_message as string | undefined;
  const allowNegativeStock = settings.allow_negative_stock === true;

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [barcodeInput, setBarcodeInput] = useState("");
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [categories, setCategories] = useState<Category[]>([]);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [toasts, setToasts] = useState<Toast[]>([]);

  const [customers, setCustomers] = useState<Customer[]>([]);
  const [customerId, setCustomerId] = useState<string>("");
  const [showCustomerDrop, setShowCustomerDrop] = useState(false);

  const [loyaltyPhone, setLoyaltyPhone] = useState("");
  const [loyaltyCard, setLoyaltyCard] = useState<LoyaltyCard | null>(null);
  const [loyaltyLoading, setLoyaltyLoading] = useState(false);
  const [customerNotFound, setCustomerNotFound] = useState(false);
  const [newCustomerOpen, setNewCustomerOpen] = useState(false);
  const [newCustomerName, setNewCustomerName] = useState("");
  const [redeemEnabled, setRedeemEnabled] = useState(false);
  const [redeemAmount, setRedeemAmount] = useState<string>("0");

  const [payOpen, setPayOpen] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payMethod, setPayMethod] = useState<"cash" | "card" | "split">("cash");
  const [splitAmount, setSplitAmount] = useState<string>("");
  const [receipt, setReceipt] = useState<Invoice | null>(null);

  const [weightModal, setWeightModal] = useState<{ product: Product; open: boolean }>({ product: {} as Product, open: false });
  const [weightInput, setWeightInput] = useState("1.000");

  const [quickAddOpen, setQuickAddOpen] = useState(false);
  const [quickAddBarcode, setQuickAddBarcode] = useState("");
  const [quickAddName, setQuickAddName] = useState("");
  const [quickAddPrice, setQuickAddPrice] = useState("");
  const [quickAdding, setQuickAdding] = useState(false);

  const [shift, setShift] = useState<Shift | null>(null);
  const [shiftLoading, setShiftLoading] = useState(true);

  const [promoResult, setPromoResult] = useState<PromotionApplyResult | null>(null);
  const [heldCart, setHeldCart] = useState<CartItem[]>([]);

  const [startShiftOpen, setStartShiftOpen] = useState(false);
  const [closeShiftOpen, setCloseShiftOpen] = useState(false);
  const [openingCash, setOpeningCash] = useState("");
  const [prevClosedActual, setPrevClosedActual] = useState(0);
  const [actualCash, setActualCash] = useState("");
  const [zReportData, setZReportData] = useState<Record<string, unknown> | null>(null);
  const [closingResult, setClosingResult] = useState<{ shift: Shift; z_report: Record<string, unknown> } | null>(null);
  const [closingLoading, setClosingLoading] = useState(false);

  const zReportAny = zReportData as Record<string, unknown> | null;
  const zCashSales = parseFloat(String((zReportAny?.payment_breakdown as Record<string, number> | undefined)?.cash ?? "0")) || 0;
  const zCashRefunds = parseFloat(String(zReportAny?.cash_refunds ?? "0")) || 0;

  const expectedCash =
    (parseFloat(String(shift?.opening_balance ?? "0")) || 0) +
    zCashSales -
    zCashRefunds;

  const barcodeRef = useRef<HTMLInputElement>(null);

  const addToast = useCallback((type: Toast["type"], message: string) => {
    const id = ++toastCounter;
    setToasts((prev) => [...prev, { id, type, message }]);
    setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), 3000);
  }, []);

  useEffect(() => {
    try {
      const saved = localStorage.getItem(CART_KEY);
      if (saved) {
        const parsed = JSON.parse(saved) as CartItem[];
        if (Array.isArray(parsed) && parsed.length > 0) setCart(parsed);
      }
    } catch {}
  }, []);

  useEffect(() => {
    if (cart.length > 0) localStorage.setItem(CART_KEY, JSON.stringify(cart));
    else localStorage.removeItem(CART_KEY);
  }, [cart]);

  const fetchProducts = useCallback(() => {
    if (!token || !business) return;
    setLoading(true);
    const params: Record<string, string | number> = {};
    if (searchQuery.trim()) params.search = searchQuery.trim();
    if (categoryId !== null) params.category_id = categoryId;
    Products.list(token, business.id, params)
      .then((res) => setProducts(res.data))
      .catch(() => addToast("error", t("common.error")))
      .finally(() => setLoading(false));
  }, [token, business, searchQuery, categoryId, addToast, t]);

  useEffect(() => {
    const timer = setTimeout(fetchProducts, 300);
    return () => clearTimeout(timer);
  }, [fetchProducts]);

  useEffect(() => {
    if (!token || !business || !crmEnabled) return;
    Customers.list(token, business.id, { per_page: 100 })
      .then((res) => setCustomers(res.data))
      .catch(() => {});
  }, [token, business, crmEnabled]);

  const loadShift = useCallback(() => {
    if (!token || !business) return;
    setShiftLoading(true);
    fetchCurrentShift(token, business.id)
      .then((s) => setShift(s))
      .catch(() => setShift(null))
      .finally(() => setShiftLoading(false));
  }, [token, business]);

  useEffect(() => {
    loadShift();
  }, [loadShift]);

  useEffect(() => {
    if (closeShiftOpen) {
      const id = setTimeout(loadShift, 0);
      return () => clearTimeout(id);
    }
  }, [closeShiftOpen, loadShift]);

  const openStartShift = useCallback(async () => {
    setStartShiftOpen(true);
    if (!token || !business) return;
    try {
      const last = await fetchLastClosedShift(token, business.id);
      const base = parseFloat(String(last?.actual_cash ?? "0")) || 0;
      setPrevClosedActual(base);
      setOpeningCash(base > 0 ? base.toFixed(2) : "");
    } catch {
      setPrevClosedActual(0);
      setOpeningCash("");
    }
  }, [token, business]);

  const handleStartShift = useCallback(async () => {
    if (!token || !business) return;
    const amount = parseFloat(openingCash);
    if (isNaN(amount) || amount < 0) {
      addToast("error", t("pos.toast.open_amount_required"));
      return;
    }
    const carried = Math.min(prevClosedActual, amount);
    try {
      await Shifts.create(token, business.id, { opening_balance: amount, carried_balance: carried });
      addToast("success", t("pos.toast.shift_opened"));
      setStartShiftOpen(false);
      setOpeningCash("");
      setPrevClosedActual(0);
      loadShift();
    } catch {
      addToast("error", t("pos.toast.shift_open_failed"));
    }
  }, [token, business, openingCash, prevClosedActual, addToast, loadShift, t]);

  const openCloseShift = useCallback(async () => {
    if (!shift) return;
    setClosingResult(null);
    setActualCash("");
    setCloseShiftOpen(true);
    setZReportData(null);
    try {
      const data = await fetchZReport(token!, business!.id, shift.id);
      setZReportData(data.z_report as Record<string, unknown>);
      if (data.shift) setShift(data.shift);
    } catch {
      // fallback: show basic shift info
    }
  }, [shift, token, business]);

  const handleCloseShift = useCallback(async () => {
    if (!token || !business || !shift) return;
    const cash = parseFloat(actualCash);
    if (isNaN(cash) || cash < 0) {
      addToast("error", t("pos.toast.actual_cash_required"));
      return;
    }
    setClosingLoading(true);
    try {
      await closeShift(token, business.id, shift.id, {
        actual_cash: cash,
        ...(shift.payment_breakdown ? { payment_breakdown: shift.payment_breakdown } : {}),
      });
      const report = await fetchZReport(token, business.id, shift.id);
      setClosingResult(report as { shift: Shift; z_report: Record<string, unknown> });
      addToast("success", t("pos.toast.shift_closed"));
      setShift(null);
    } catch {
      addToast("error", t("pos.toast.shift_close_failed"));
    } finally {
      setClosingLoading(false);
    }
  }, [token, business, shift, actualCash, addToast, t]);

  useEffect(() => {
    if (!token || !business) return;
    let cancelled = false;
    const id = window.setTimeout(() => {
      if (cancelled) return;
      if (!promotionsEnabled || cart.length === 0) {
        setPromoResult(null);
        return;
      }
      applyPromotions(token, business.id, {
        items: cart.map((i) => ({
          product_id: i.product.id,
          quantity: i.product.is_weighable ? i.weight ?? 1 : i.quantity,
          unit_price: i.product.effective_price ?? i.product.price ?? 0,
        })),
        local_time: localClockTime(),
      })
        .then((res) => {
          if (!cancelled) setPromoResult(res);
        })
        .catch(() => {
          if (!cancelled) setPromoResult(null);
        });
    }, 300);
    return () => {
      cancelled = true;
      window.clearTimeout(id);
    };
  }, [token, business, promotionsEnabled, cart]);

  useEffect(() => {
    if (!token || !business) return;
    fetchCategories(token, business.id)
      .then(setCategories)
      .catch(() => setCategories([]));
  }, [token, business]);

  useEffect(() => {
    try {
      const saved = localStorage.getItem(HOLD_KEY);
      if (saved) {
        const parsed = JSON.parse(saved) as CartItem[];
        if (Array.isArray(parsed)) setHeldCart(parsed);
      }
    } catch {}
  }, []);

  useEffect(() => { barcodeRef.current?.focus(); }, []);

  const handleBarcode = useCallback(
    async (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key !== "Enter" || !token || !business || !barcodeInput.trim()) return;
      try {
        const product = await lookupBarcode(token, business.id, barcodeInput.trim());
        if (!allowNegativeStock && product.stock_quantity <= 0) {
          addToast("error", `${product.name} — ${t("pos.out_of_stock")}`);
          setBarcodeInput("");
          return;
        }
        if (product.scale_weight) {
          setWeightInput(String(product.scale_weight));
          setWeightModal({ product, open: true });
        } else if (product.is_weighable) {
          setWeightInput("1.000");
          setWeightModal({ product, open: true });
        } else {
          addToCart(product);
          if (isSupermarket || rapidMode) barcodeRef.current?.focus();
        }
        setBarcodeInput("");
      } catch (err) {
        if (err instanceof ApiError && err.status === 404) {
          // Unknown barcode — offer to quick-add the product on the fly.
          const raw = barcodeInput.trim();
          const digits = raw.replace(/\D/g, "").slice(0, 13);
          setQuickAddBarcode(digits);
          setQuickAddName("");
          setQuickAddPrice("");
          setQuickAddOpen(true);
        } else {
          addToast("error", t("pos.product_not_found"));
        }
      }
    },
    [token, business, barcodeInput, addToast, t, allowNegativeStock]
  );

  const addToCart = useCallback(
    (product: Product, weight?: number) => {
      setCart((prev) => {
        if (product.is_weighable && weight) {
          return [...prev, { product, quantity: 1, weight }];
        }
        const existing = prev.find((i) => i.product.id === product.id && !i.product.is_weighable);
        if (existing) return prev.map((i) => (i.product.id === product.id ? { ...i, quantity: i.quantity + 1 } : i));
        return [...prev, { product, quantity: 1 }];
      });
    },
    []
  );

  const handleWeightConfirm = useCallback(() => {
    const w = parseFloat(weightInput);
    if (isNaN(w) || w <= 0) return addToast("error", t("pos.toast.invalid_weight"));
    addToCart(weightModal.product, w);
    setWeightModal({ product: {} as Product, open: false });
    barcodeRef.current?.focus();
  }, [weightInput, weightModal.product, addToCart, addToast, t]);

  const handleQuickAdd = useCallback(async () => {
    if (!token || !business || !quickAddName.trim()) return;
    const price = parseFloat(quickAddPrice);
    if (isNaN(price) || price < 0) return;
    setQuickAdding(true);
    try {
      const saved = await quickAddProduct(token, business.id, {
        name: quickAddName.trim(),
        barcode: quickAddBarcode || null,
        selling_price: price,
      });
      addToCart(saved);
      addToast("success", t("pos.quick_add_created", { name: saved.name }));
      setQuickAddOpen(false);
      setQuickAddBarcode("");
      setQuickAddName("");
      setQuickAddPrice("");
    } catch (err) {
      addToast("error", err instanceof ApiError ? err.message : t("pos.quick_add_failed"));
    } finally {
      setQuickAdding(false);
      barcodeRef.current?.focus();
    }
  }, [token, business, quickAddName, quickAddPrice, quickAddBarcode, addToCart, addToast, t]);

  const updateQty = useCallback((idx: number, delta: number) => {
    setCart((prev) => {
      const updated = prev.map((item, i) => (i === idx ? { ...item, quantity: Math.max(0, item.quantity + delta) } : item));
      return updated.filter((i) => i.quantity > 0);
    });
  }, []);

  const removeFromCart = useCallback((idx: number) => {
    setCart((prev) => prev.filter((_, i) => i !== idx));
  }, []);

  const clearCart = useCallback(() => {
    setCart([]);
    setCustomerId("");
    setLoyaltyCard(null);
    setRedeemEnabled(false);
    setRedeemAmount("0");
    setLoyaltyPhone("");
  }, []);

  const holdCart = useCallback(() => {
    if (cart.length === 0) return;
    localStorage.setItem(HOLD_KEY, JSON.stringify(cart));
    setHeldCart(cart);
    clearCart();
    addToast("info", t("pos.hold_cart"));
  }, [cart, clearCart, addToast, t]);

  const recallCart = useCallback(() => {
    if (heldCart.length === 0) return;
    setCart(heldCart);
    setHeldCart([]);
    localStorage.removeItem(HOLD_KEY);
    addToast("info", t("pos.recall_cart"));
  }, [heldCart, addToast, t]);

  const handleProductClick = useCallback((product: Product) => {
    if (!allowNegativeStock && product.stock_quantity <= 0) {
      addToast("error", `${product.name} — ${t("pos.out_of_stock")}`);
      return;
    }
    if (product.is_weighable) {
      setWeightInput("1.000");
      setWeightModal({ product, open: true });
    } else {
      addToCart(product);
    }
  }, [addToCart, addToast, t, allowNegativeStock]);

  const handleLoyaltyLookup = useCallback(async () => {
    if (!crmEnabled || !token || !business || !loyaltyPhone.trim()) return;
    setLoyaltyLoading(true);
    setCustomerNotFound(false);
    try {
      const result = await lookupCustomerByPhone(token, business.id, loyaltyPhone.trim());
      const cust = result.customer;
      setCustomerId(String(cust.id));
      setCustomerNotFound(false);
      if (result.newly_created) {
        addToast("info", t("pos.new_customer_added"));
      } else {
        addToast("success", cust.name);
      }
      try {
        const { lookupLoyaltyByPhone } = await import("@/lib/api");
        const card = await lookupLoyaltyByPhone(token, business.id, loyaltyPhone.trim());
        setLoyaltyCard(card);
        setRedeemEnabled(false);
        setRedeemAmount("0");
        if (card.points_balance >= loyaltyAlertThreshold) {
          const discountJod = Math.floor(card.points_balance / loyaltyRedemptionRate) * loyaltyRedemptionJod;
          addToast("info", t("pos.loyalty_alert", { points: String(card.points_balance), amount: formatCurrency(discountJod, locale) }));
        }
      } catch {
        setLoyaltyCard(null);
      }
    } catch {
      setLoyaltyCard(null);
      setCustomerNotFound(true);
    } finally {
      setLoyaltyLoading(false);
    }
  }, [crmEnabled, token, business, loyaltyPhone, addToast, t, loyaltyAlertThreshold, loyaltyRedemptionRate, loyaltyRedemptionJod, locale]);

  const subtotal = useMemo(() => cart.reduce((sum, i) => {
    const price = i.product.effective_price ?? i.product.price ?? 0;
    return sum + (i.product.is_weighable ? price * (i.weight ?? 1) : price * i.quantity);
  }, 0), [cart]);

  const taxTotal = useMemo(() => cart.reduce((sum, i) => {
    const price = i.product.effective_price ?? i.product.price ?? 0;
    const rate = i.product.tax_rate ?? 0;
    if (rate <= 0) return sum;
    const lineTotal = i.product.is_weighable ? price * (i.weight ?? 1) : price * i.quantity;
    if (taxInclusive) {
      return sum + (lineTotal - lineTotal / (1 + rate / 100));
    }
    return sum + lineTotal * (rate / 100);
  }, 0), [cart, taxInclusive]);

  const promotionDiscount = promoResult?.total_discount ?? 0;
  const appliedPromos = promoResult?.applied_promotions ?? [];

  const loyaltyDiscount = useMemo(() => {
    if (!redeemEnabled || !loyaltyCard) return 0;
    const requestedPoints = parseInt(redeemAmount, 10);
    if (isNaN(requestedPoints) || requestedPoints <= 0) return 0;
    const maxPointsByAmount = Math.floor((subtotal - promotionDiscount) / loyaltyRedemptionJod);
    const points = Math.min(requestedPoints, loyaltyCard.points_balance, maxPointsByAmount);
    if (points <= 0) return 0;
    return Math.round(points * loyaltyRedemptionJod * 100) / 100;
  }, [redeemEnabled, loyaltyCard, redeemAmount, subtotal, promotionDiscount, loyaltyRedemptionJod]);

  const total = Math.max(0, taxInclusive
    ? subtotal - promotionDiscount - loyaltyDiscount
    : subtotal + taxTotal - promotionDiscount - loyaltyDiscount
  );

  const selectedCustomerName = useMemo(() => customers.find((c) => String(c.id) === customerId)?.name, [customers, customerId]);

  const openPay = useCallback(() => {
    if (!shift || shift.status !== "open") {
      addToast("error", t("pos.toast.shift_required"));
      return;
    }
    setPayMethod("cash");
    setSplitAmount("");
    setPayOpen(true);
  }, [shift, addToast, t]);

  const handlePayment = useCallback(async () => {
    if (!token || !business || cart.length === 0) return;
    setPaying(true);
    try {
      const cashAmount = payMethod === "split" ? (parseFloat(splitAmount) || 0) : 0;
      const cardAmount = payMethod === "split" ? Math.max(0, total - cashAmount) : 0;
      const invoice = await Invoices.create(token, business.id, {
        customer_id: customerId ? parseInt(customerId) : null,
        items: cart.map((i) => ({
          product_id: i.product.id,
          name: i.product.name,
          quantity: i.product.is_weighable ? i.weight ?? 1 : i.quantity,
          unit_price: i.product.effective_price ?? i.product.price ?? 0,
          tax_rate: i.product.tax_rate ?? 0,
          discount: 0,
        })),
        discount_amount: promotionDiscount + loyaltyDiscount,
        tax_amount: taxTotal,
        ...(appliedPromos.length > 0 && {
          promotions: appliedPromos.map((ap) => ({ id: ap.id, discount: ap.discount })),
        }),
        payment_method: payMethod,
        payment_status: "paid",
        ...(payMethod === "split" && {
          split_details: [
            { method: "cash", amount: cashAmount },
            { method: "card", amount: cardAmount },
          ],
        }),
      });

      if (loyaltyCard && redeemEnabled && loyaltyDiscount > 0) {
        // Redemption happens here (client-side); earning is automatic server-side
        // on the paid invoice (LoyaltyService::earnForInvoice).
        try {
          const redeemedPoints = Math.round(loyaltyDiscount / loyaltyRedemptionJod);
          await createLoyaltyTransaction(token, business.id, {
            loyalty_card_id: loyaltyCard.id,
            type: "redeem",
            points: redeemedPoints,
            description: t("pos.toast.pos_redeemption"),
          });
        } catch {
          addToast("error", t("pos.toast.redeem_failed"));
        }
      }

      setPayOpen(false);
      setReceipt(invoice);
      setCart([]);
      setCustomerId("");
      setLoyaltyCard(null);
      setRedeemEnabled(false);
      setRedeemAmount("0");
      setLoyaltyPhone("");
      localStorage.removeItem(CART_KEY);
      addToast("success", invoice.invoice_number ?? t("pos.toast.invoice_created"));
      fetchProducts();
      loadShift();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t("pos.toast.invoice_create_failed");
      addToast("error", msg);
    } finally {
      setPaying(false);
    }
  }, [token, business, cart, customerId, total, promotionDiscount, loyaltyDiscount, taxTotal, payMethod, splitAmount, loyaltyCard, redeemEnabled, addToast, t, fetchProducts, loadShift]);

  const displayQty = (item: CartItem) => item.product.is_weighable ? `${(item.weight ?? 1).toFixed(3)} ${t("pos.kg")}` : String(item.quantity);

  const bizType = config?.business_type || "";
  const isSupermarket = isSupermarketVertical(bizType);
  const payMethods: ("cash" | "card" | "split")[] = ["cash", "card", "split"];
  function ProductIcon({ size = "md" }: { size?: "md" | "lg" }) {
    const cls = size === "lg" ? "w-12 h-12" : "w-8 h-8";
    if (["clothing_apparel", "apparel"].includes(bizType))
      return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25V9m3.75 2.25v6m-6-6v6m-3-6v6M3 9h18M9 3h6M9 3v3h6V3" /></svg>;
    if (["restaurant", "cafe", "bakery"].includes(bizType))
      return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M12 8.25v-1.5m0 1.5c-1.355 0-2.697.056-4.024.166C6.845 8.51 6 9.473 6 10.608v2.513m6-4.871c1.355 0 2.697.056 4.024.166C17.155 8.51 18 9.473 18 10.608v2.513M15 8.25v-1.5m-6 1.5v-1.5m12 9.75l-1.5.75a3.354 3.354 0 01-3 0 3.354 3.354 0 00-3 0 3.354 3.354 0 01-3 0 3.354 3.354 0 00-3 0 3.354 3.354 0 01-3 0L3 16.5" /></svg>;
    if (isSupermarketVertical(bizType))
      return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" /></svg>;
    if (["pharmacy", "drug_store"].includes(bizType))
      return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>;
    if (["electronics", "electrical"].includes(bizType))
      return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>;
    return <svg className={`${cls} text-muted`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}><path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M8.25 3.75h7.5M10.5 7.5V3.75m3 3.75V3.75" /></svg>;
  }

  // Auto-print the thermal receipt when the setting is enabled.
  useEffect(() => {
    if (!receipt || !autoPrintReceipt) return;
    document.body.classList.add("pos-receipt-print");
    const timer = setTimeout(() => {
      window.print();
    }, 400);
    return () => {
      clearTimeout(timer);
      document.body.classList.remove("pos-receipt-print");
    };
  }, [receipt, autoPrintReceipt]);

  return (
    <div className="flex gap-4 h-[calc(100vh-8rem)]">
      <Toasts toasts={toasts} />

      {/* ── Left: Products ── */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Header row: barcode + search + shift indicator */}
        <div className="flex gap-3 mb-3 items-center">
          <div className="relative w-56">
            <ScanBarcode className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
            <input
              ref={barcodeRef}
              type="text"
              placeholder={t("pos.scan_barcode")}
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              onKeyDown={handleBarcode}
              autoFocus={isSupermarket}
              className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
            />
          </div>
          <div className="relative flex-1">
            <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
            <input
              type="text"
              placeholder={t("pos.search")}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full ps-10 pe-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-border-hover transition-colors"
            />
          </div>
          <button
            onClick={shift?.status === "open" ? openCloseShift : openStartShift}
            disabled={shiftLoading}
            className={`flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium border cursor-pointer transition-colors ${shift?.status === "open" ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20" : "bg-red-500/10 border-red-500/30 text-red-400 hover:bg-red-500/20"}`}
          >
            {shiftLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : shift?.status === "open" ? <Clock className="w-3.5 h-3.5" /> : <AlertTriangle className="w-3.5 h-3.5" />}
            {shiftLoading ? "..." : shift?.status === "open" ? `${t("pos.shift")} #${shift.shift_number}` : t("pos.toast.no_shift")}
          </button>
        </div>

        {/* Category tabs */}
        {(() => {
          const topCats = categories.filter((c) => !c.parent_id);
          const childCats = categories.filter((c) => c.parent_id);
          const childrenOf = (id: number) => childCats.filter((c) => c.parent_id === id);
          const activeTop = categoryId === null ? null : (categories.find((c) => c.id === categoryId)?.parent_id ?? categoryId);
          return (
            <div className="space-y-2 mb-3">
              <div className="flex gap-2 overflow-x-auto pb-1 no-scrollbar">
                <button
                  onClick={() => setCategoryId(null)}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors ${categoryId === null ? "bg-primary text-foreground" : "bg-card/80 text-muted hover:bg-card-hover hover:text-foreground border border-border"}`}
                >
                  {t("pos.all")}
                </button>
                {topCats.map((cat) => {
                  const isActive = activeTop === cat.id;
                  const dot = cat.color ? cat.color : undefined;
                  return (
                    <button
                      key={cat.id}
                      onClick={() => setCategoryId(cat.id)}
                      className={`px-3.5 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 ${isActive ? "bg-primary text-foreground" : "bg-card/80 text-muted hover:bg-card-hover hover:text-foreground border border-border"}`}
                    >
                      {dot && <span className="w-2 h-2 rounded-full" style={{ backgroundColor: dot }} />}
                      {locale === "ar" && cat.name_ar ? cat.name_ar : cat.name}
                    </button>
                  );
                })}
              </div>
              {activeTop !== null &&
                childrenOf(activeTop).length > 0 && (
                  <div className="flex gap-2 overflow-x-auto pb-1 no-scrollbar ps-1">
                    {childrenOf(activeTop).map((child) => (
                      <button
                        key={child.id}
                        onClick={() => setCategoryId(child.id)}
                        className={`px-3 py-1 rounded-lg text-[11px] font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 ${categoryId === child.id ? "bg-primary/80 text-foreground" : "bg-card/60 text-muted hover:bg-card-hover hover:text-foreground border border-border"}`}
                      >
                        {child.color && <span className="w-2 h-2 rounded-full" style={{ backgroundColor: child.color }} />}
                        {locale === "ar" && child.name_ar ? child.name_ar : child.name}
                      </button>
                    ))}
                  </div>
                )}
            </div>
          );
        })()}

        {/* Product grid */}
        <div className="flex-1 overflow-y-auto pe-2">
          {loading ? (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
              {Array.from({ length: 10 }).map((_, i) => (
                <div key={i} className="glass rounded-xl p-4 animate-pulse h-32" />
              ))}
            </div>
          ) : products.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-48 text-muted text-sm gap-2">
              <ShoppingCart className="w-8 h-8 opacity-30" />
              {t("pos.no_products")}
            </div>
          ) : (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
              {products.map((p) => {
                const stockQty = Number(p.stock_quantity) || 0;
                const isExpired = (p as unknown as { is_fully_expired?: boolean }).is_fully_expired === true;
                const isOOS = stockQty <= 0;
                const unavailable = (isOOS && !allowNegativeStock) || isExpired;
                const isDiscrete = !p.is_weighable && (!p.unit || ["pcs", "piece", "item", "box", "unit", "each"].includes(p.unit));
                const stockDisplay = isDiscrete
                  ? Math.floor(stockQty)
                  : stockQty.toFixed(2);
                const stockUnit = p.is_weighable ? (p.unit || "kg") : "";
                return (
                  <motion.button
                    key={p.id}
                    whileHover={unavailable ? undefined : { scale: 1.02 }}
                    whileTap={unavailable ? undefined : { scale: 0.98 }}
                    onClick={() => handleProductClick(p)}
                    disabled={unavailable}
                    className={`glass rounded-xl p-4 text-start transition-all group relative ${unavailable ? "opacity-50 cursor-not-allowed" : "hover:border-border-hover/80"}`}
                  >
                    {isExpired && (
                      <span className="absolute inset-0 z-10 flex items-center justify-center">
                        <span className="bg-red-600/90 text-white text-[10px] font-bold px-2 py-1 rounded-md rotate-[-15deg] uppercase tracking-wider">
                          {t("pos.expired")}
                        </span>
                      </span>
                    )}
                    {!isExpired && isOOS && !allowNegativeStock && (
                      <span className="absolute inset-0 z-10 flex items-center justify-center">
                        <span className="bg-red-500/90 text-white text-[10px] font-bold px-2 py-1 rounded-md rotate-[-15deg] uppercase tracking-wider">
                          {t("pos.out_of_stock")}
                        </span>
                      </span>
                    )}
                    {p.is_weighable && (
                      <span className="absolute top-2 end-2 text-[10px] bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-md px-1.5 py-0.5 flex items-center gap-1">
                        <Weight className="w-2.5 h-2.5" /> {p.unit || "kg"}
                      </span>
                    )}
                    <div className="w-full h-28 rounded-lg overflow-hidden bg-card/40 flex items-center justify-center mb-2">
                      {p.image_url ? (
                        <img
                          src={p.image_url}
                          alt={p.name}
                          className="w-full h-full object-cover"
                          loading="lazy"
                          onError={(e) => {
                            (e.target as HTMLImageElement).style.display = "none";
                            const next = (e.target as HTMLImageElement).nextElementSibling;
                            if (next) next.classList.remove("hidden");
                          }}
                        />
                      ) : null}
                      <div className={p.image_url ? "hidden" : ""}>
                        <ProductIcon size="lg" />
                      </div>
                    </div>
                    <p className="text-sm font-medium text-foreground truncate pe-6">{p.name}</p>
                    {p.sku && <p className="text-xs text-muted mt-0.5">{p.sku}</p>}
                    <p className="text-sm font-semibold text-primary-light mt-2">
                      {p.is_on_sale && p.sale_price != null && p.sale_price > 0 ? (
                        <>
                          <span className="line-through text-muted me-1.5">{formatCurrency(p.price, locale)}</span>
                          {formatCurrency(p.sale_price, locale)}
                        </>
                      ) : (
                        formatCurrency(p.price, locale)
                      )}
                      {p.tax_rate > 0 && (
                        <span className="ms-1.5 text-[10px] font-medium text-muted bg-card/60 px-1.5 py-0.5 rounded-md">
                          +{p.tax_rate}% {t("common.tax")}
                        </span>
                      )}
                    </p>
                    <div className="flex items-center justify-between mt-1.5">
                      <p className={`text-xs ${isOOS ? "text-red-400 font-medium" : "text-muted"}`}>
                        {t("pos.stock")}: {stockDisplay}{stockUnit ? ` ${stockUnit}` : ""}
                      </p>
                      {p.category && <p className="text-[10px] text-muted truncate max-w-[80px]">{p.category}</p>}
                    </div>
                  </motion.button>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {/* ── Right: Cart ── */}
      <div className="w-96 flex flex-col glass rounded-2xl">
        {/* Cart header */}
        <div className="px-5 py-4 border-b border-border">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <ShoppingCart className="w-5 h-5 text-primary-light" />
              <h2 className="text-base font-semibold text-foreground">{t("pos.order_summary")}</h2>
            </div>
            {cart.length > 0 && (
              <div className="flex items-center gap-1">
                <button onClick={holdCart} className="text-muted hover:text-amber-400 transition-colors p-1" title={t("pos.hold_cart")}>
                  <Pause className="w-4 h-4" />
                </button>
                <button onClick={clearCart} className="text-muted hover:text-red-400 transition-colors p-1" title={t("pos.clear_cart")}>
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            )}
          </div>
          <p className="text-xs text-muted mt-1">{t("pos.items", { count: String(cart.length) })}</p>

          {/* Customer selector */}
          {crmEnabled ? (
            <div className="relative mt-3">
              <button
                onClick={() => setShowCustomerDrop(!showCustomerDrop)}
                className="w-full flex items-center justify-between px-3 py-2 bg-card/80 border border-border rounded-xl text-xs text-foreground hover:border-border-hover transition-colors"
              >
                <span className="flex items-center gap-2">
                  <User className="w-3.5 h-3.5 text-muted" />
                  {selectedCustomerName ?? t("invoices.walk_in")}
                </span>
                <ChevronDown className="w-3.5 h-3.5 text-muted" />
              </button>
              {showCustomerDrop && (
                <div className="absolute top-full start-0 end-0 mt-1 bg-card border border-border rounded-xl shadow-xl z-30 max-h-40 overflow-y-auto">
                  <button onClick={() => { setCustomerId(""); setShowCustomerDrop(false); }} className="w-full text-start px-3 py-2 text-xs text-muted hover:bg-card-hover transition-colors">
                    {t("invoices.walk_in")}
                  </button>
                  {customers.map((c) => (
                    <button key={c.id} onClick={() => { setCustomerId(String(c.id)); setShowCustomerDrop(false); }} className="w-full text-start px-3 py-2 text-xs text-foreground hover:bg-card-hover transition-colors">
                      {c.name} {c.phone && <span className="text-muted ms-1">({c.phone})</span>}
                    </button>
                  ))}
                </div>
              )}
            </div>
          ) : (
            <div className="mt-3 flex items-center gap-2 px-3 py-2 bg-card/60 border border-border/50 rounded-xl text-xs text-muted">
              <User className="w-3.5 h-3.5" />
              {t("invoices.walk_in")}
            </div>
          )}
        </div>

        {/* Cart items */}
        <div className="flex-1 overflow-y-auto p-4 space-y-2">
          <AnimatePresence>
            {cart.length === 0 ? (
              <div className="text-center py-8 space-y-3">
                <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="text-sm text-muted">
                  {t("pos.empty_cart")}
                </motion.p>
                {heldCart.length > 0 && (
                  <button onClick={recallCart} className="inline-flex items-center gap-2 px-4 py-2 bg-primary/20 text-primary-light border border-primary/30 rounded-xl text-xs font-medium hover:bg-primary/30 transition-colors">
                    <Play className="w-3.5 h-3.5" />
                    {t("pos.recall_cart", { count: String(heldCart.length) })}
                  </button>
                )}
              </div>
            ) : (
              cart.map((item, idx) => (
                <motion.div
                  key={`${item.product.id}-${idx}`}
                  initial={{ opacity: 0, x: 20 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: -20 }}
                  className="flex items-center gap-2 py-2 border-b border-border/50 last:border-0"
                >
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-foreground truncate">{item.product.name}</p>
                    <p className="text-xs text-muted">
                      {item.product.is_weighable
                        ? `${formatCurrency(item.product.effective_price ?? item.product.price ?? 0, locale)}/${t("pos.kg")}`
                        : item.product.is_on_sale && item.product.sale_price != null && item.product.sale_price > 0
                          ? (
                            <>
                              <span className="line-through me-1">{formatCurrency(item.product.price, locale)}</span>
                              {formatCurrency(item.product.sale_price, locale)}
                            </>
                          )
                          : formatCurrency(item.product.price, locale)}
                    </p>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <button onClick={() => updateQty(idx, -1)} className="p-1 rounded-lg bg-card-hover text-muted hover:text-foreground transition-colors">
                      <Minus className="w-3 h-3" />
                    </button>
                    <span className="text-xs text-foreground w-8 text-center font-medium">{displayQty(item)}</span>
                    <button onClick={() => updateQty(idx, 1)} className="p-1 rounded-lg bg-card-hover text-muted hover:text-foreground transition-colors">
                      <Plus className="w-3 h-3" />
                    </button>
                  </div>
                  <p className="text-xs font-medium text-foreground w-20 text-end">
                    {formatCurrency(
                      item.product.is_weighable
                        ? (item.product.effective_price ?? item.product.price ?? 0) * (item.weight ?? 1)
                        : (item.product.effective_price ?? item.product.price ?? 0) * item.quantity,
                      locale
                    )}
                  </p>
                  <button onClick={() => removeFromCart(idx)} className="p-1 text-muted hover:text-red-400 transition-colors">
                    <X className="w-3.5 h-3.5" />
                  </button>
                </motion.div>
              ))
            )}
          </AnimatePresence>
        </div>

        {/* Loyalty section */}
        {loyaltyEnabled && (
        <div className="px-5 py-3 border-t border-border space-y-2">
          <p className="text-xs font-medium text-muted flex items-center gap-1.5">
            <Gift className="w-3.5 h-3.5" /> {t("loyalty.title")}
          </p>
          <div className="flex gap-2">
            <input
              type="text"
              placeholder={t("customers.search")}
              value={loyaltyPhone}
              onChange={(e) => setLoyaltyPhone(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && handleLoyaltyLookup()}
              className="flex-1 px-3 py-1.5 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
            />
            <button
              onClick={handleLoyaltyLookup}
              disabled={loyaltyLoading || !loyaltyPhone.trim()}
              className="px-3 py-1.5 bg-primary/20 text-primary-light rounded-lg text-xs font-medium hover:bg-primary/30 transition-colors disabled:opacity-40"
            >
              {loyaltyLoading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : t("common.search")}
            </button>
          </div>

          {customerNotFound && (
            <div className="bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2 space-y-2">
              <p className="text-xs text-amber-400">{t("loyalty.no_customer")}</p>
              <div className="flex gap-2">
                <input
                  type="text"
                  placeholder={t("customers.name")}
                  value={newCustomerName}
                  onChange={(e) => setNewCustomerName(e.target.value)}
                  className="flex-1 px-2 py-1 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                />
                <button
                  onClick={async () => {
                    if (!token || !business || !newCustomerName.trim()) return;
                    try {
                      const { Customers } = await import("@/lib/api");
                      const cust = await Customers.create(token, business.id, { name: newCustomerName.trim(), phone: loyaltyPhone.trim() });
                      setCustomerId(String(cust.id));
                      setCustomerNotFound(false);
                      setNewCustomerName("");
                      addToast("success", cust.name);
                      const { lookupLoyaltyByPhone } = await import("@/lib/api");
                      try {
                        const card = await lookupLoyaltyByPhone(token, business.id, loyaltyPhone.trim());
                        setLoyaltyCard(card);
                      } catch {}
                    } catch {
                      addToast("error", t("pos.toast.customer_create_failed"));
                    }
                  }}
                  disabled={!newCustomerName.trim()}
                  className="px-3 py-1 bg-primary/20 text-primary-light rounded-lg text-xs font-medium hover:bg-primary/30 transition-colors disabled:opacity-40"
                >
                  <UserPlus className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          )}

          {customerId && !customerNotFound && selectedCustomerName && (
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-1.5 flex items-center gap-2 text-xs">
              <Check className="w-3.5 h-3.5 text-emerald-400" />
              <span className="text-emerald-400 font-medium">{selectedCustomerName}</span>
            </div>
          )}

          {loyaltyCard && (
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-2 space-y-2">
              <div className="flex items-center justify-between text-xs">
                <span className="text-emerald-400 font-medium">{loyaltyCard.card_number}</span>
                <span className="text-emerald-400">{loyaltyCard.points_balance} {t("pos.pts")}</span>
              </div>
              <label className="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                <input
                  type="checkbox"
                  checked={redeemEnabled}
                  onChange={(e) => { setRedeemEnabled(e.target.checked); if (!e.target.checked) setRedeemAmount("0"); }}
                  className="accent-emerald-500 w-3.5 h-3.5"
                />
                {t("loyalty.redeem_points")}
              </label>
              {redeemEnabled && (
                <input
                  type="number"
                  min="0"
                  max={loyaltyCard.points_balance}
                  value={redeemAmount}
                  onChange={(e) => setRedeemAmount(e.target.value)}
                  placeholder={t("loyalty.redeem_points")}
                  className="w-full px-2 py-1 bg-card/80 border border-border rounded-lg text-xs text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                />
              )}
            </div>
          )}
        </div>
        )}

        {/* Order summary */}
        <div className="px-5 py-4 border-t border-border space-y-1.5">
          <div className="flex justify-between text-sm">
            <span className="text-muted">{taxInclusive ? t("pos.subtotal_incl_tax") : t("pos.subtotal")}</span>
            <span className="text-foreground">{formatCurrency(subtotal, locale)}</span>
          </div>
          {!taxInclusive && (
            <div className="flex justify-between text-sm">
              <span className="text-muted">{t("pos.tax")}</span>
              <span className="text-foreground">{formatCurrency(taxTotal, locale)}</span>
            </div>
          )}
          {taxInclusive && taxTotal > 0 && (
            <div className="flex justify-between text-xs text-muted">
              <span>{t("pos.tax_included")}</span>
              <span>{formatCurrency(taxTotal, locale)}</span>
            </div>
          )}
          {promotionDiscount > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-amber-400 flex items-center gap-1"><Percent className="w-3 h-3" /> {appliedPromos.length > 0 ? appliedPromos.map((ap) => ap.name).join(", ") : t("pos.promo_discount")}</span>
              <span className="text-amber-400">-{formatCurrency(promotionDiscount, locale)}</span>
            </div>
          )}
          {loyaltyDiscount > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-emerald-400 flex items-center gap-1"><Gift className="w-3 h-3" /> {t("pos.loyalty")}</span>
              <span className="text-emerald-400">-{formatCurrency(loyaltyDiscount, locale)}</span>
            </div>
          )}
          <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/50">
            <span className="text-foreground">{t("pos.total")}</span>
            <span className="text-foreground">{formatCurrency(total, locale)}</span>
          </div>
          <div className="flex gap-2 mt-3">
            <button onClick={openPay} disabled={cart.length === 0} className="flex-1 py-3 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
              {t("pos.pay")} {formatCurrency(total, locale)}
            </button>
          </div>
        </div>
      </div>

      {/* ── Weight Modal ── */}
      <AnimatePresence>
        {weightModal.open && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setWeightModal({ product: {} as Product, open: false })} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-sm">
                <h3 className="text-lg font-semibold text-foreground mb-1 flex items-center gap-2"><Weight className="w-5 h-5 text-primary-light" /> {t("pos.enter_weight")}</h3>
                <p className="text-sm text-muted mb-4">{weightModal.product.name} — {formatCurrency(weightModal.product.effective_price ?? weightModal.product.price ?? 0, locale)}/{t("pos.kg")}</p>
                <input
                  type="number"
                  step="0.001"
                  min="0.001"
                  value={weightInput}
                  onChange={(e) => setWeightInput(e.target.value)}
                  onKeyDown={(e) => e.key === "Enter" && handleWeightConfirm()}
                  autoFocus
                  className="w-full px-4 py-3 bg-card/80 border border-border rounded-xl text-lg text-foreground text-center font-mono focus:outline-none focus:border-primary/50 transition-colors"
                />
                <p className="text-center text-sm text-muted mt-1">{t("pos.kg")}</p>
                <div className="flex gap-3 mt-4">
                  <button onClick={() => setWeightModal({ product: {} as Product, open: false })} className="flex-1 py-2.5 text-sm text-muted hover:text-foreground border border-border rounded-xl transition-colors">
                    {t("pos.cancel")}
                  </button>
                  <button onClick={handleWeightConfirm} className="flex-1 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
                    {t("pos.add")}
                  </button>
                </div>
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* ── Quick Add Product Modal ── */}
      <AnimatePresence>
        {quickAddOpen && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setQuickAddOpen(false)} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-sm space-y-4">
                <h3 className="text-lg font-semibold text-foreground flex items-center gap-2">
                  <PackagePlus className="w-5 h-5 text-primary-light" /> {t("pos.quick_add_title")}
                </h3>
                <p className="text-sm text-muted">{t("pos.quick_add_desc")}</p>

                <div>
                  <label className="text-xs text-muted mb-1 block">{t("common.barcode")}</label>
                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={13}
                    value={quickAddBarcode}
                    readOnly
                    dir="ltr"
                    className="w-full px-3 py-2 bg-card/40 border border-border rounded-xl text-sm text-muted focus:outline-none focus:border-border-hover"
                  />
                </div>

                <div>
                  <label className="text-xs text-muted mb-1 block">{t("common.name")}</label>
                  <input
                    type="text"
                    value={quickAddName}
                    onChange={(e) => setQuickAddName(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && handleQuickAdd()}
                    autoFocus
                    placeholder={t("pos.quick_add_name_placeholder")}
                    className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50"
                  />
                </div>

                <div>
                  <label className="text-xs text-muted mb-1 block">{t("pos.quick_add_price")}</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={quickAddPrice}
                    onChange={(e) => setQuickAddPrice(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && handleQuickAdd()}
                    placeholder="0.00"
                    className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50"
                  />
                </div>

                <div className="flex gap-3 mt-4">
                  <button onClick={() => setQuickAddOpen(false)} className="flex-1 py-2.5 text-sm text-muted hover:text-foreground border border-border rounded-xl transition-colors">
                    {t("pos.cancel")}
                  </button>
                  <button
                    onClick={handleQuickAdd}
                    disabled={quickAdding || !quickAddName.trim() || !(parseFloat(quickAddPrice) >= 0)}
                    className="flex-1 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                  >
                    {quickAdding ? t("common.processing") : t("pos.quick_add_save")}
                  </button>
                </div>
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* ── Payment Modal ── */}
      <AnimatePresence>
        {payOpen && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => !paying && setPayOpen(false)} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-sm space-y-4">
                <h3 className="text-lg font-semibold text-foreground">{t("pos.select_payment")}</h3>
                <p className="text-2xl font-bold text-foreground text-center">{formatCurrency(total, locale)}</p>

                {/* Customer in payment modal */}
                <div>
                  <label className="text-xs text-muted mb-1 block">{t("invoices.customer")}</label>
                  <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground focus:outline-none focus:border-border-hover">
                    <option value="">{t("invoices.walk_in")}</option>
                    {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                </div>

                {/* Payment method tabs */}
                <div className="flex gap-2 flex-wrap">
                  {payMethods.map((m) => {
                    const disabled = m === "split" && !allowSplit;
                    return (
                      <button
                        key={m}
                        onClick={() => {
                          if (disabled) return;
                          setPayMethod(m);
                          if (m === "split") setSplitAmount((Math.round((total / 2) * 100) / 100).toFixed(2));
                        }}
                        disabled={disabled}
                        title={disabled ? t("pos.split_disabled") : ""}
                        className={`flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-medium border transition-colors flex-1 ${disabled ? "opacity-40 cursor-not-allowed" : payMethod === m ? "bg-primary/20 border-primary/50 text-primary-light" : "bg-card/80 border-border text-muted hover:text-foreground hover:border-border-hover"}`}
                      >
                        {m === "cash" && <Banknote className="w-4 h-4" />}
                        {m === "card" && <CreditCard className="w-4 h-4" />}
                        {m === "split" && <Layers className="w-4 h-4" />}
                        {t(`pos.${m}`)}
                      </button>
                    );
                  })}
                </div>

                {payMethod === "split" && (
                  <div className="space-y-1.5">
                    <input
                      type="number"
                      value={splitAmount}
                      onChange={(e) => setSplitAmount(e.target.value)}
                      placeholder={t("pos.cash_amount", { total: formatCurrency(total, locale) })}
                      className="w-full px-3 py-2 bg-card/80 border border-border rounded-xl text-sm text-foreground placeholder:text-muted focus:outline-none focus:border-primary/50 transition-colors"
                    />
                    <p className="text-xs text-muted text-center">
                      {t("pos.remaining_card")}: {formatCurrency(Math.max(0, total - (parseFloat(splitAmount) || 0)), locale)}
                    </p>
                  </div>
                )}

                <button
                  onClick={handlePayment}
                  disabled={paying || cart.length === 0}
                  className="w-full flex items-center justify-center gap-3 py-3 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-50"
                >
                  {paying ? <Loader2 className="w-5 h-5 animate-spin" /> : <Check className="w-5 h-5" />}
                  {paying ? t("common.processing") : `${t("pos.pay")} ${formatCurrency(total, locale)}`}
                </button>

                <button onClick={() => setPayOpen(false)} disabled={paying} className="w-full py-2.5 text-sm text-muted hover:text-foreground transition-colors">
                  {t("pos.cancel")}
                </button>
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* ── Receipt Modal ── */}
      <AnimatePresence>
        {receipt && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setReceipt(null)} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 print:hidden" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }}
                className={`glass rounded-2xl p-6 w-full ${receiptPaperWidth === "58mm" ? "max-w-[220px]" : "max-w-sm"} space-y-4 receipt-print-area`}>
                <div className="flex flex-col items-center gap-1">
                  {config?.logo && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={config.logo}
                      alt={business?.name ?? t("pos.payment_successful")}
                      className="max-h-[50px] w-auto object-contain"
                    />
                  )}
                  <div className="text-sm font-semibold text-center text-foreground">
                    {business?.name ?? config?.business_name ?? ""}
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center">
                    <Check className="w-5 h-5 text-emerald-400" />
                  </div>
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">{t("pos.payment_successful")}</h3>
                    <p className="text-xs text-muted">{receipt.invoice_number}</p>
                  </div>
                </div>
                <div className="bg-card/60 border border-border rounded-xl p-4 space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted">{taxInclusive ? t("pos.subtotal_incl_tax") : t("pos.subtotal")}</span>
                    <span className="text-foreground">{formatCurrency(receipt.subtotal ?? 0, locale)}</span>
                  </div>
                  {!taxInclusive && (
                    <div className="flex justify-between">
                      <span className="text-muted">{t("pos.tax")}</span>
                      <span className="text-foreground">{formatCurrency(receipt.tax_amount ?? 0, locale)}</span>
                    </div>
                  )}
                  {taxInclusive && (receipt.tax_amount ?? 0) > 0 && (
                    <div className="flex justify-between text-xs text-muted">
                      <span>{t("pos.tax_included")}</span>
                      <span>{formatCurrency(receipt.tax_amount ?? 0, locale)}</span>
                    </div>
                  )}
                  {receipt.discount_amount > 0 && (
                    <div className="flex justify-between">
                      <span className="text-amber-400">{t("pos.discount")}</span>
                      <span className="text-amber-400">-{formatCurrency(receipt.discount_amount, locale)}</span>
                    </div>
                  )}
                  <div className="border-t border-border pt-2 flex justify-between font-semibold">
                    <span className="text-foreground">{t("pos.total")}</span>
                    <span className="text-foreground">{formatCurrency(receipt.net_amount ?? 0, locale)}</span>
                  </div>
                </div>
                {(receiptFooterMessage || footerTerms) && (
                  <div className="text-xs text-muted text-center border-t border-border pt-2 whitespace-pre-wrap">{receiptFooterMessage || footerTerms}</div>
                )}
                <div className="text-xs text-muted text-center">
                  {t("pos.items", { count: String(receipt.items?.length ?? 0) })} &middot; {t(`pos.${payMethod}`)}
                </div>
                <div className="flex gap-2 print:hidden">
                  <button
                    onClick={() => {
                      document.body.classList.add("pos-receipt-print");
                      window.print();
                      document.body.classList.remove("pos-receipt-print");
                    }}
                    className="flex items-center justify-center gap-2 py-2.5 px-3 bg-card/60 hover:bg-card-hover/60 border border-border text-foreground rounded-xl text-sm font-medium transition-colors"
                  >
                    <Printer className="w-4 h-4" /> {t("pos.print_receipt")}
                  </button>
                  <button onClick={() => setReceipt(null)} className="flex-1 py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
                    {t("common.done")}
                  </button>
                </div>
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* ── Start Shift Modal ── */}
      <AnimatePresence>
        {startShiftOpen && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setStartShiftOpen(false)} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-sm space-y-4">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center">
                    <DollarSign className="w-5 h-5 text-emerald-400" />
                  </div>
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">{t("pos.open_shift")}</h3>
                    <p className="text-xs text-muted">{t("pos.open_shift_desc")}</p>
                  </div>
                </div>
                {prevClosedActual > 0 && (
                  <div className="flex items-center justify-between px-4 py-2.5 rounded-xl text-xs bg-emerald-500/10 border border-emerald-500/30">
                    <span className="text-muted flex items-center gap-2">
                      <ArrowRight className="w-3.5 h-3.5" />
                      {t("shift.handover")}
                    </span>
                    <span className="text-emerald-400 font-medium font-mono">{formatCurrency(prevClosedActual, locale)}</span>
                  </div>
                )}
                <div>
                  <label className="text-xs text-muted mb-1 block">{t("pos.opening_cash_float", { currency: locale === "ar" ? "\u062F.\u0623" : "JOD" })}</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={openingCash}
                    onChange={(e) => setOpeningCash(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && handleStartShift()}
                    autoFocus
                    placeholder="0.00"
                    className="w-full px-4 py-3 bg-card/80 border border-border rounded-xl text-lg text-foreground text-center font-mono focus:outline-none focus:border-primary/50 transition-colors"
                  />
                </div>
                <div className="flex gap-3">
                  <button onClick={() => setStartShiftOpen(false)} className="flex-1 py-2.5 text-sm text-muted hover:text-foreground border border-border rounded-xl transition-colors">
                    {t("pos.cancel")}
                  </button>
                  <button onClick={handleStartShift} className="flex-1 py-2.5 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 border border-emerald-500/30 rounded-xl text-sm font-medium transition-colors">
                    {t("pos.start_shift")}
                  </button>
                </div>
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>

      {/* ── Close Shift / Reconciliation Modal ── */}
      <AnimatePresence>
        {closeShiftOpen && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => { if (!closingLoading) setCloseShiftOpen(false); }} className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50" />
            <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
              <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="glass rounded-2xl p-6 w-full max-w-md space-y-4 max-h-[90vh] overflow-y-auto">
                {closingResult ? (
                  <>
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center">
                        <Check className="w-5 h-5 text-emerald-400" />
                      </div>
                      <div>
                        <h3 className="text-lg font-semibold text-foreground">{t("pos.shift_closed")}</h3>
                        <p className="text-xs text-muted">{t("pos.z_report")} #{(closingResult.z_report as any)?.report_number ?? closingResult.shift.shift_number}</p>
                      </div>
                    </div>
                    <div className="bg-card/60 border border-border rounded-xl p-4 space-y-2">
                      <div className="flex justify-between text-sm">
                        <span className="text-muted">{t("shift.total_sales")}</span>
                        <span className="text-foreground font-medium">{formatCurrency(closingResult.shift.total_sales ?? 0, locale)}</span>
                      </div>
                      <div className="flex justify-between text-sm">
                        <span className="text-muted">{t("pos.total_transactions")}</span>
                        <span className="text-foreground font-medium">{closingResult.shift.total_transactions ?? 0}</span>
                      </div>
                      <div className="flex justify-between text-sm">
                        <span className="text-muted">{t("pos.total_discounts")}</span>
                        <span className="text-foreground font-medium">{formatCurrency(closingResult.shift.total_discounts ?? 0, locale)}</span>
                      </div>
                      <div className="border-t border-border pt-2 mt-2">
                        <div className="flex justify-between text-sm">
                          <span className="text-muted">{t("pos.opening_cash")}</span>
                          <span className="text-foreground">{formatCurrency(closingResult.shift.opening_balance ?? 0, locale)}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                          <span className="text-muted">{t("pos.expected_cash")}</span>
                          <span className="text-foreground">{formatCurrency(closingResult.shift.expected_cash ?? 0, locale)}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                          <span className="text-muted">{t("shift.actual_cash")}</span>
                          <span className="text-foreground">{formatCurrency(closingResult.shift.actual_cash ?? 0, locale)}</span>
                        </div>
                        <div className={`flex justify-between text-sm font-semibold ${(closingResult.shift.variance ?? 0) >= 0 ? "text-emerald-400" : "text-red-400"}`}>
                          <span>{(closingResult.shift.variance ?? 0) >= 0 ? t("pos.over") : t("pos.short")}</span>
                          <span>{formatCurrency(Math.abs(closingResult.shift.variance ?? 0), locale)}</span>
                        </div>
                      </div>
                    </div>
                    <button onClick={() => { setCloseShiftOpen(false); loadShift(); }} className="w-full py-2.5 bg-primary hover:bg-primary-light text-foreground rounded-xl text-sm font-medium transition-colors">
                      {t("pos.done")}
                    </button>
                  </>
                ) : (
                  <>
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-xl bg-amber-500/20 flex items-center justify-center">
                        <Clock className="w-5 h-5 text-amber-400" />
                      </div>
                      <div>
                        <h3 className="text-lg font-semibold text-foreground">{t("shift.close_shift")}</h3>
                        <p className="text-xs text-muted">{t("pos.close_shift_desc", { number: String(shift?.shift_number ?? "") })}</p>
                      </div>
                    </div>

                    <div className="bg-card/60 border border-border rounded-xl p-4 space-y-2">
                      <div className="flex justify-between text-sm">
                        <span className="text-muted">{t("shift.total_sales")}</span>
                        <span className="text-foreground font-medium">{formatCurrency(shift?.total_sales ?? 0, locale)}</span>
                      </div>
                      <div className="flex justify-between text-sm">
                        <span className="text-muted">{t("pos.total_transactions")}</span>
                        <span className="text-foreground font-medium">{shift?.total_transactions ?? 0}</span>
                      </div>
                      {(zReportData as Record<string, any>)?.payment_breakdown && (
                        <div className="border-t border-border pt-2 mt-2 space-y-1">
                          <p className="text-xs font-medium text-muted mb-1">{t("shift.payment_breakdown")}</p>
                          {Object.entries((zReportData as Record<string, any>).payment_breakdown as Record<string, number>).map(([method, amount]) => (
                            <div key={method} className="flex justify-between text-sm">
                              <span className="text-muted capitalize">{method.replace("_", " ")}</span>
                              <span className="text-foreground">{formatCurrency(amount, locale)}</span>
                            </div>
                          ))}
                        </div>
                      )}
                      <div className="border-t border-border pt-2 mt-2">
                        <div className="flex justify-between text-sm">
                          <span className="text-muted">{t("pos.opening_cash")}</span>
                          <span className="text-foreground">{formatCurrency(shift?.opening_balance ?? 0, locale)}</span>
                        </div>
                        {zCashRefunds > 0 && (
                          <div className="flex justify-between text-sm">
                            <span className="text-muted">{t("pos.cash_refunds")}</span>
                            <span className="text-red-400">-{formatCurrency(zCashRefunds, locale)}</span>
                          </div>
                        )}
                        <div className="flex justify-between text-sm">
                          <span className="text-muted">{t("pos.expected_cash")}</span>
                          <span className="text-foreground">{formatCurrency(expectedCash, locale)}</span>
                        </div>
                      </div>
                    </div>

                    <div>
                      <label className="text-xs text-muted mb-1 block">{t("pos.actual_cash_counted", { currency: locale === "ar" ? "\u062F.\u0623" : "JOD" })}</label>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={actualCash}
                        onChange={(e) => setActualCash(e.target.value)}
                        onKeyDown={(e) => e.key === "Enter" && !closingLoading && handleCloseShift()}
                        autoFocus
                        placeholder={expectedCash > 0 ? expectedCash.toFixed(2) : "0.00"}
                        className="w-full px-4 py-3 bg-card/80 border border-border rounded-xl text-lg text-foreground text-center font-mono focus:outline-none focus:border-primary/50 transition-colors"
                      />
                    </div>

                    {actualCash && !isNaN(parseFloat(actualCash)) && (
                      <div className={`flex items-center justify-between px-4 py-2.5 rounded-xl text-sm font-medium ${(() => {
                        const expected = expectedCash;
                        const variance = parseFloat(actualCash) - expected;
                        return variance >= 0 ? "bg-emerald-500/10 border border-emerald-500/30 text-emerald-400" : "bg-red-500/10 border border-red-500/30 text-red-400";
                      })()}`}>
                        <span className="flex items-center gap-2">
                          <ArrowRight className="w-4 h-4" />
                          {(() => {
                            const expected = expectedCash;
                            const variance = parseFloat(actualCash) - expected;
                            return variance >= 0 ? t("pos.over") : t("pos.short");
                          })()}
                        </span>
                        <span>{formatCurrency(Math.abs((() => {
                          const expected = expectedCash;
                          return parseFloat(actualCash) - expected;
                        })()), locale)}</span>
                      </div>
                    )}

                    <div className="flex gap-3">
                      <button onClick={() => setCloseShiftOpen(false)} disabled={closingLoading} className="flex-1 py-2.5 text-sm text-muted hover:text-foreground border border-border rounded-xl transition-colors disabled:opacity-40">
                        {t("pos.cancel")}
                      </button>
                      <button onClick={handleCloseShift} disabled={closingLoading} className="flex-1 py-2.5 bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 rounded-xl text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        {closingLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
                        {closingLoading ? t("pos.closing") : t("shift.close_shift")}
                      </button>
                    </div>
                  </>
                )}
              </motion.div>
            </div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
}

function Toasts({ toasts }: { toasts: Toast[] }) {
  return (
    <div className="fixed bottom-6 end-6 z-[100] space-y-2 pointer-events-none">
      <AnimatePresence>
        {toasts.map((toast) => (
          <motion.div
            key={toast.id}
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -10, scale: 0.95 }}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg pointer-events-auto border ${toast.type === "success" ? "bg-emerald-500/20 text-emerald-400 border-emerald-500/30" : toast.type === "error" ? "bg-red-500/20 text-red-400 border-red-500/30" : "bg-blue-500/20 text-blue-400 border-blue-500/30"}`}
          >
            {toast.type === "success" && <Check className="w-4 h-4" />}
            {toast.type === "error" && <X className="w-4 h-4" />}
            {toast.type === "info" && <AlertTriangle className="w-4 h-4" />}
            {toast.message}
          </motion.div>
        ))}
      </AnimatePresence>
    </div>
  );
}
