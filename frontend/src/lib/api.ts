import {
  BootstrapConfig,
  BusinessTypeItem,
  BusinessSettingsResponse,
  BusinessSettingsUpdateResponse,
  DashboardStats,
  SupermarketDashboardStats,
  LoginResponse,
  PlatformTenant,
  PlatformTenantCreatePayload,
  PlatformTenantUpdatePayload,
  PlatformTenantsResponse,
  PlatformLeadsResponse,
  PlatformLeadApprovePayload,
  ApproveLeadResponse,
  RejectLeadResponse,
  ActivationPreview,
  ActivateResponse,
  TenantLoginResponse,
  PaginatedResponse,
  Product,
  Customer,
  CustomerStatement,
  Supplier,
  Invoice,
  Payment,
  PurchaseOrder,
  PurchaseOrderPayment,
  SupplierProduct,
  Account,
  JournalEntry,
  ProductBatch,
  ProductVariant,
  Collection,
  SerialNumber,
  WarrantyClaim,
  ServiceTicket,
  LoyaltyCard,
  LoyaltyTransaction,
  Campaign,
  CampaignStats,
  CustomerSegments,
  Promotion,
  Recipe,
  Shift,
  InventoryAdjustment,
  ZReport,
  FiscalYear,
  BankReconciliation,
  BankReconciliationLine,
  TrialBalance,
  ProfitAndLoss,
  BalanceSheet,
  CashFlow,
  GeneralLedger,
  SalesSummary,
  StockValuation,
  SupplierAging,
  AccountsReceivable,
  AccountsPayable,
  PayableStatement,
  AuthUser,
  Warehouse,
  StockMovement,
  GoodsReceipt,
  Category,
  Role,
  RoleMatrix,
  ReturnExchange,
  ReturnablePreview,
  PromotionApplyResult,
  SupplierLedger,
} from "./types";
import { removeAuthCookie } from "@/lib/cookie";

export type {
  BusinessTypeItem,
  DashboardStats,
  Category,
};

const API_BASE = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api/v1";

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }
}

function authHeaders(token: string, businessId: string): Record<string, string> {
  return {
    Authorization: `Bearer ${token}`,
    "X-Business-ID": businessId,
    Accept: "application/json",
  };
}

async function apiFetch<T>(url: string, options?: RequestInit): Promise<T> {
  const res = await fetch(url, options);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401 && typeof window !== "undefined") {
      localStorage.removeItem("sx_token");
      localStorage.removeItem("sx_user");
      localStorage.removeItem("sx_business");
      removeAuthCookie();
      if (!window.location.pathname.startsWith("/login")) {
        window.location.href = "/login";
      }
    }
    throw new ApiError(data?.message || "Request failed", res.status, data?.errors);
  }
  return data;
}

function qs(params: Record<string, string | number | undefined>): string {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== "");
  return entries.length ? "?" + entries.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join("&") : "";
}

const BASE_DOMAIN = process.env.NEXT_PUBLIC_SUPERX_BASE_DOMAIN;

export function resolveTenantHost(): string | null {
  if (typeof window === "undefined") return null;
  const hostParam = new URLSearchParams(window.location.search).get("host");
  if (hostParam) return hostParam;
  const host = window.location.hostname;
  if (BASE_DOMAIN && host !== BASE_DOMAIN && host.endsWith("." + BASE_DOMAIN)) return host;
  // Local dev: `{subdomain}.localhost` (e.g. brillivo.localhost) is the tenant
  // host equivalent of `{subdomain}.{BASE_DOMAIN}` in production.
  if (host !== "localhost" && host.endsWith(".localhost")) return host;
  return null;
}

export function storeLoginUrl(domain: string): string {
  if (typeof window === "undefined") return `https://${domain}/login`;
  const local =
    ["localhost", "127.0.0.1"].includes(window.location.hostname) ||
    window.location.hostname.endsWith(".localhost");
  if (local) {
    // Keep dev on the local origin: http://{subdomain}.localhost:{port}/login
    const subdomain = domain.split(".")[0];
    const port = window.location.port ? `:${window.location.port}` : "";
    return `${window.location.protocol}//${subdomain}.localhost${port}/login`;
  }
  return `${window.location.protocol}//${domain}/login`;
}

// ─── Auth ──────────────────────────────────────────────
export async function fetchBusinessTypes(): Promise<BusinessTypeItem[]> {
  return apiFetch(`${API_BASE}/business-types`);
}

export async function loginRequest(username: string, password: string): Promise<LoginResponse> {
  return apiFetch(`${API_BASE}/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ username, password }),
  });
}

export async function submitLead(payload: {
  name: string;
  business_name: string;
  business_type_id?: number | null;
  phone: string;
  city?: string | null;
}): Promise<{ message?: string }> {
  return apiFetch(`${API_BASE}/leads`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

// ─── B2B activation & tenant auth ───────────────────────
export async function fetchActivation(token: string): Promise<ActivationPreview> {
  return apiFetch(`${API_BASE}/activate/${token}`);
}

export async function activateStore(payload: {
  token: string;
  name: string;
  email: string;
  username?: string;
  password: string;
  password_confirmation: string;
  currency?: string;
  tax_enabled?: boolean;
  default_tax_rate?: number;
  tax_calculation_method?: "inclusive" | "exclusive";
}): Promise<ActivateResponse> {
  return apiFetch(`${API_BASE}/activate`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function tenantLogin(
  host: string,
  username: string,
  password: string
): Promise<TenantLoginResponse> {
  const res = await fetch(`${API_BASE}/tenant-login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ host, username, password }),
  });
  const data = await res.json().catch(() => ({}));
  // 422 carries { needs_activation, activation_url, ... } — not a hard error.
  if (data?.needs_activation) return data as TenantLoginResponse;
  if (!res.ok) throw new ApiError(data?.message || "Request failed", res.status, data?.errors);
  return data as TenantLoginResponse;
}

function ownerHeaders(token?: string): Record<string, string> {
  const h: Record<string, string> = { Accept: "application/json" };
  if (token) h.Authorization = `Bearer ${token}`;
  const secret = process.env.NEXT_PUBLIC_SUPERX_OWNER_SECRET;
  if (secret) h["X-Owner-Secret"] = secret;
  return h;
}

export async function fetchPlatformTenants(token: string): Promise<PlatformTenantsResponse> {
  return apiFetch(`${API_BASE}/platform/tenants`, { headers: ownerHeaders(token) });
}

export async function createPlatformTenant(token: string, payload: PlatformTenantCreatePayload): Promise<PlatformTenant> {
  return apiFetch(`${API_BASE}/platform/tenants`, {
    method: "POST",
    headers: { ...ownerHeaders(token), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function fetchPlatformTenant(token: string, id: string): Promise<PlatformTenant> {
  return apiFetch(`${API_BASE}/platform/tenants/${id}`, { headers: ownerHeaders(token) });
}

export async function updatePlatformTenant(
  token: string,
  id: string,
  payload: PlatformTenantUpdatePayload
): Promise<PlatformTenant> {
  return apiFetch(`${API_BASE}/platform/tenants/${id}`, {
    method: "PUT",
    headers: { ...ownerHeaders(token), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function fetchPlatformLeads(token: string): Promise<PlatformLeadsResponse> {
  return apiFetch(`${API_BASE}/platform/leads`, { headers: ownerHeaders(token) });
}

export async function approvePlatformLead(
  token: string,
  leadId: number,
  payload: PlatformLeadApprovePayload
): Promise<ApproveLeadResponse> {
  return apiFetch(`${API_BASE}/platform/leads/${leadId}/approve`, {
    method: "POST",
    headers: { ...ownerHeaders(token), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function rejectPlatformLead(token: string, leadId: number): Promise<RejectLeadResponse> {
  return apiFetch(`${API_BASE}/platform/leads/${leadId}/reject`, {
    method: "POST",
    headers: { ...ownerHeaders(token), "Content-Type": "application/json" },
    body: JSON.stringify({}),
  });
}

export async function fetchBootstrap(token: string, businessId: string): Promise<BootstrapConfig> {
  return apiFetch(`${API_BASE}/bootstrap`, { headers: authHeaders(token, businessId) });
}

export async function logoutRequest(token: string, businessId: string): Promise<void> {
  await fetch(`${API_BASE}/logout`, { method: "POST", headers: authHeaders(token, businessId) }).catch(() => {});
}

export async function fetchTenantDashboard(token: string, businessId: string): Promise<DashboardStats | SupermarketDashboardStats> {
  return apiFetch(`${API_BASE}/tenant/dashboard`, { headers: authHeaders(token, businessId) });
}

export async function fetchSupermarketDashboard(token: string, businessId: string): Promise<SupermarketDashboardStats> {
  return apiFetch(`${API_BASE}/dashboard/supermarket`, { headers: authHeaders(token, businessId) });
}

// ─── Generic CRUD helper ───────────────────────────────
function crud<T>(path: string, token: string, businessId: string) {
  const h = authHeaders(token, businessId);
  const base = `${API_BASE}/${path}`;
  return {
    list: (params?: Record<string, string | number>) =>
      apiFetch<PaginatedResponse<T>>(`${base}${qs(params || {})}`, { headers: h }),
    get: (id: number | string) =>
      apiFetch<T>(`${base}/${id}`, { headers: h }),
    create: (data: Record<string, unknown>) =>
      apiFetch<T>(base, { method: "POST", headers: { ...h, "Content-Type": "application/json" }, body: JSON.stringify(data) }),
    update: (id: number | string, data: Record<string, unknown>) =>
      apiFetch<T>(`${base}/${id}`, { method: "PUT", headers: { ...h, "Content-Type": "application/json" }, body: JSON.stringify(data) }),
    patch: (id: number | string, data: Record<string, unknown>) =>
      apiFetch<T>(`${base}/${id}`, { method: "PATCH", headers: { ...h, "Content-Type": "application/json" }, body: JSON.stringify(data) }),
    delete: (id: number | string) =>
      apiFetch<{ message: string }>(`${base}/${id}`, { method: "DELETE", headers: h }),
  };
}

// ─── Core Resources ────────────────────────────────────
export const api = {
  users: <const>"users",
  products: <const>"products",
  customers: <const>"customers",
  suppliers: <const>"suppliers",
  invoices: <const>"invoices",
  payments: <const>"payments",
  purchaseOrders: <const>"purchase-orders",
  accounts: <const>"accounts",
  journalEntries: <const>"journal-entries",
  productBatches: <const>"product-batches",
  productVariants: <const>"product-variants",
  collections: <const>"collections",
  serialNumbers: <const>"serial-numbers",
  warrantyClaims: <const>"warranty-claims",
  serviceTickets: <const>"service-tickets",
  promotions: <const>"promotions",
  recipes: <const>"recipes",
  shifts: <const>"shifts",
  inventoryAdjustments: <const>"inventory-adjustments",
  fiscalYears: <const>"fiscal-years",
  bankReconciliations: <const>"bank-reconciliations",
  warehouses: <const>"warehouses",
  stockMovements: <const>"stock-movements",
  goodsReceipts: <const>"goods-receipts",
  returnsExchanges: <const>"returns-exchanges",
  roles: <const>"roles",
  campaigns: <const>"campaigns",
};

// ─── Invoice Status ──────────────────────────────
export function updateInvoiceStatus(token: string, bizId: string, invoiceId: number | string, paymentStatus: string) {
  return apiFetch<Invoice>(`${API_BASE}/invoices/${invoiceId}/status`, {
    method: "PATCH",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify({ payment_status: paymentStatus }),
  });
}

// ─── Business Settings ─────────────────────────────
export function generateProductVariants(token: string, bizId: string, payload: Record<string, unknown>) {
  return apiFetch<{ data: ProductVariant[] }>(`${API_BASE}/product-variants/generate`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export function fetchBusinessSettings(token: string, bizId: string) {
  return apiFetch<BusinessSettingsResponse>(`${API_BASE}/businesses/settings`, {
    headers: authHeaders(token, bizId),
  });
}

export function updateBusinessSettings(token: string, bizId: string, settings: Record<string, unknown>) {
  return apiFetch<BusinessSettingsUpdateResponse>(`${API_BASE}/businesses/settings`, {
    method: "PUT",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(settings),
  });
}

export function changePassword(token: string, bizId: string, payload: {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}) {
  return apiFetch<{ message: string }>(`${API_BASE}/profile/password`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export function updateProfile(token: string, bizId: string, payload: {
  name?: string;
  email?: string;
  avatar?: string | null;
}) {
  return apiFetch<{ user: AuthUser }>(`${API_BASE}/profile`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function nextAccountCode(token: string, bizId: string, type: string): Promise<{ code: string }> {
  return apiFetch(`${API_BASE}/accounts/next-code${qs({ type })}`, { headers: authHeaders(token, bizId) });
}

export async function payPurchaseOrder(
  token: string,
  bizId: string,
  purchaseOrderId: number | string,
  payload: { amount: number; method: string; reference_number?: string; notes?: string },
): Promise<PurchaseOrderPayment> {
  return apiFetch(`${API_BASE}/purchase-orders/${purchaseOrderId}/pay`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

// ─── Supplier Catalog (supplier_products) ─────────────
export async function fetchSupplierCatalog(
  token: string,
  bizId: string,
  supplierId: number | string,
): Promise<SupplierProduct[]> {
  return apiFetch(`${API_BASE}/suppliers/${supplierId}/products`, { headers: authHeaders(token, bizId) });
}

export async function addSupplierCatalogItem(
  token: string,
  bizId: string,
  supplierId: number | string,
  data: { product_id?: number | null; name?: string; catalog_cost?: number | null },
): Promise<SupplierProduct> {
  return apiFetch(`${API_BASE}/suppliers/${supplierId}/products`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}

export async function removeSupplierCatalogItem(
  token: string,
  bizId: string,
  supplierId: number | string,
  itemId: number | string,
): Promise<{ message: string }> {
  return apiFetch(`${API_BASE}/suppliers/${supplierId}/products/${itemId}`, {
    method: "DELETE",
    headers: authHeaders(token, bizId),
  });
}

export async function fetchSupplierLedger(
  token: string,
  bizId: string,
  supplierId: number | string,
): Promise<SupplierLedger> {
  return apiFetch(`${API_BASE}/suppliers/${supplierId}/ledger`, { headers: authHeaders(token, bizId) });
}

export async function payGoodsReceipt(
  token: string,
  bizId: string,
  receiptId: number | string,
  payload: { amount: number; method?: string; reference_number?: string; notes?: string },
): Promise<PurchaseOrderPayment> {
  return apiFetch(`${API_BASE}/goods-receipts/${receiptId}/pay`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export function createResource<T>(path: string) {
  return {
    list: (token: string, bizId: string, params?: Record<string, string | number>) =>
      crud<T>(path, token, bizId).list(params),
    get: (token: string, bizId: string, id: number | string) =>
      crud<T>(path, token, bizId).get(id),
    create: (token: string, bizId: string, data: Record<string, unknown>) =>
      crud<T>(path, token, bizId).create(data),
    update: (token: string, bizId: string, id: number | string, data: Record<string, unknown>) =>
      crud<T>(path, token, bizId).update(id, data),
    patch: (token: string, bizId: string, id: number | string, data: Record<string, unknown>) =>
      crud<T>(path, token, bizId).patch(id, data),
    delete: (token: string, bizId: string, id: number | string) =>
      crud<T>(path, token, bizId).delete(id),
  };
}

// ─── Typed Resource APIs ───────────────────────────────
export const Users = createResource<AuthUser>(api.users);
export const Products = createResource<Product>(api.products);
export const Customers = createResource<Customer>(api.customers);
export const Suppliers = createResource<Supplier>(api.suppliers);
export const Invoices = createResource<Invoice>(api.invoices);
export const Payments = createResource<Payment>(api.payments);
export const PurchaseOrders = createResource<PurchaseOrder>(api.purchaseOrders);
export const Accounts = createResource<Account>(api.accounts);
export const JournalEntries = createResource<JournalEntry>(api.journalEntries);
export const ProductBatches = createResource<ProductBatch>(api.productBatches);
export const ProductVariants = createResource<ProductVariant>(api.productVariants);
export const Collections = createResource<Collection>(api.collections);
export const SerialNumbers = createResource<SerialNumber>(api.serialNumbers);
export const WarrantyClaims = createResource<WarrantyClaim>(api.warrantyClaims);
export const ServiceTickets = createResource<ServiceTicket>(api.serviceTickets);
export const Promotions = createResource<Promotion>(api.promotions);
export const Recipes = createResource<Recipe>(api.recipes);
export const Shifts = createResource<Shift>(api.shifts);
export const InventoryAdjustments = createResource<InventoryAdjustment>(api.inventoryAdjustments);
export const FiscalYears = createResource<FiscalYear>(api.fiscalYears);
export const BankReconciliations = createResource<BankReconciliation>(api.bankReconciliations);
export const Warehouses = createResource<Warehouse>(api.warehouses);
export const StockMovements = createResource<StockMovement>(api.stockMovements);
export const GoodsReceipts = createResource<GoodsReceipt>(api.goodsReceipts);
export const ReturnExchanges = createResource<ReturnExchange>(api.returnsExchanges);
export const Roles = createResource<Role>(api.roles);

// ─── Returns & Exchanges ──────────────────────────────
export function fetchInvoiceReturnable(
  token: string,
  bizId: string,
  invoiceId: number | string,
): Promise<ReturnablePreview> {
  return apiFetch(`${API_BASE}/invoices/${invoiceId}/returnable`, { headers: authHeaders(token, bizId) });
}

// ─── RBAC Roles ──────────────────────────────────────
export function fetchRoleMatrix(token: string, bizId: string) {
  return apiFetch<RoleMatrix>(`${API_BASE}/roles/matrix`, { headers: authHeaders(token, bizId) });
}
export function updateRolePermissions(token: string, bizId: string, roleId: string, permissions: string[]) {
  return apiFetch<Role>(`${API_BASE}/roles/${roleId}/permissions`, {
    method: "PUT", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify({ permissions }),
  });
}

// ─── Customer lookup by phone ──────────────────────────
export function lookupCustomerByPhone(token: string, bizId: string, phone: string) {
  return apiFetch<{ customer: Customer; newly_created: boolean }>(`${API_BASE}/customers/lookup`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify({ phone }),
  });
}

// ─── Customer statement (AR sub-ledger) ────────────────
export function fetchCustomerStatement(token: string, bizId: string, customerId: number | string) {
  return apiFetch<CustomerStatement>(`${API_BASE}/customers/${customerId}/statement`, {
    headers: authHeaders(token, bizId),
  });
}

// ─── Loyalty (custom endpoints) ────────────────────────
export function fetchLoyaltyCards(token: string, bizId: string, params?: Record<string, string | number>) {
  return apiFetch<PaginatedResponse<LoyaltyCard>>(`${API_BASE}/loyalty/cards${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function createLoyaltyCard(token: string, bizId: string, data: Record<string, unknown>) {
  return apiFetch<LoyaltyCard>(`${API_BASE}/loyalty/cards`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function updateLoyaltyCard(token: string, bizId: string, id: number | string, data: Record<string, unknown>) {
  return apiFetch<LoyaltyCard>(`${API_BASE}/loyalty/cards/${id}`, {
    method: "PUT", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function createLoyaltyTransaction(token: string, bizId: string, data: Record<string, unknown>) {
  return apiFetch<{ transaction: LoyaltyTransaction; card: LoyaltyCard }>(`${API_BASE}/loyalty/transactions`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function fetchLoyaltyTransactions(token: string, bizId: string, params?: Record<string, string | number>) {
  return apiFetch<PaginatedResponse<LoyaltyTransaction>>(`${API_BASE}/loyalty/transactions${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function lookupLoyaltyByPhone(token: string, bizId: string, phone: string) {
  return apiFetch<LoyaltyCard>(`${API_BASE}/loyalty/lookup`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify({ phone }),
  });
}
export function fetchLoyaltyConfig(token: string, bizId: string) {
  return apiFetch<{ points_per_jod: number; redemption_rate: number; redemption_rate_display: number; alert_threshold: number; alert_discount: number; loyalty_enabled: boolean; tiers: Record<string, { min_spend: number; points_multiplier: number }> }>(`${API_BASE}/loyalty/config`, { headers: authHeaders(token, bizId) });
}

// ─── CRM Segments ──────────────────────────────────────
export function fetchCustomerSegments(token: string, bizId: string) {
  return apiFetch<CustomerSegments>(`${API_BASE}/customers/segments/stats`, { headers: authHeaders(token, bizId) });
}

// ─── Campaigns ─────────────────────────────────────────
export const Campaigns = createResource<Campaign>(api.campaigns);

export async function sendCampaign(token: string, bizId: string, campaignId: number | string): Promise<Campaign> {
  return apiFetch(`${API_BASE}/campaigns/${campaignId}/send`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
  });
}

export async function sendTestCampaign(
  token: string,
  bizId: string,
  campaignId: number | string,
  phone: string,
): Promise<{ success: boolean; sent_to: string; provider?: string | null; error?: string | null }> {
  return apiFetch(`${API_BASE}/campaigns/${campaignId}/send-test`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify({ phone }),
  });
}

export async function fetchCampaignStats(
  token: string,
  bizId: string,
  campaignId: number | string,
): Promise<CampaignStats> {
  return apiFetch(`${API_BASE}/campaigns/${campaignId}/stats`, { headers: authHeaders(token, bizId) });
}

// ─── Delivery Webhook ──────────────────────────────────
export function deliveryWebhook(bizId: string, data: Record<string, unknown>) {
  return apiFetch<{ customer: Customer; newly_created: boolean; order_id: string; platform: string }>(`${API_BASE}/delivery/webhook`, {
    method: "POST",
    headers: { ...authHeaders("", bizId), "Content-Type": "application/json", "X-Business-ID": bizId },
    body: JSON.stringify(data),
  });
}

// ─── Categories ─────────────────────────────────────────
export function fetchCategories(token: string, bizId: string) {
  return apiFetch<Category[]>(`${API_BASE}/categories`, { headers: authHeaders(token, bizId) });
}
export interface CategoryPayload {
  name: string;
  name_ar?: string | null;
  color?: string | null;
  parent_id?: number | null;
  sort_order?: number | null;
}
export function createCategory(token: string, bizId: string, data: CategoryPayload) {
  return apiFetch<Category>(`${API_BASE}/categories`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}
export function updateCategory(token: string, bizId: string, id: number, data: CategoryPayload) {
  return apiFetch<Category>(`${API_BASE}/categories/${id}`, {
    method: "PUT",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}
export function deleteCategory(token: string, bizId: string, id: number, data: { reassign_to?: number | null }) {
  return apiFetch<{ message: string }>(`${API_BASE}/categories/${id}`, {
    method: "DELETE",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}

// ─── Shift Management ──────────────────────────────────
export function fetchCurrentShift(token: string, bizId: string) {
  return apiFetch<Shift | null>(`${API_BASE}/shifts/current`, { headers: authHeaders(token, bizId) });
}
export function fetchLastClosedShift(token: string, bizId: string) {
  return apiFetch<Shift | null>(`${API_BASE}/shifts/last-closed`, { headers: authHeaders(token, bizId) });
}
export function closeShift(token: string, bizId: string, shiftId: number | string, data: { actual_cash: number; payment_breakdown?: Record<string, number> }) {
  return apiFetch<Shift>(`${API_BASE}/shifts/${shiftId}/close`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function fetchZReport(token: string, bizId: string, shiftId: number | string) {
  return apiFetch<{ shift: Shift; z_report: Record<string, unknown> }>(`${API_BASE}/shifts/${shiftId}/z-report`, { headers: authHeaders(token, bizId) });
}

// ─── Inventory Alerts ──────────────────────────────────
export function fetchExpiryAlerts(token: string, bizId: string, params?: Record<string, string | number>) {
  return apiFetch<PaginatedResponse<ProductBatch>>(`${API_BASE}/inventory-adjustments/expiry-alerts${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchLowStock(token: string, bizId: string, params?: Record<string, string | number>) {
  return apiFetch<PaginatedResponse<Product>>(`${API_BASE}/inventory-adjustments/low-stock${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function autoWasteExpiredBatches(token: string, bizId: string, dryRun?: boolean) {
  return apiFetch<{ message: string; processed: number; dry_run?: boolean }>(`${API_BASE}/inventory-adjustments/auto-waste`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify({ dry_run: dryRun ?? false }),
  });
}

// ─── Journal Entry Actions ────────────────────────────
export function postJournalEntry(token: string, bizId: string, id: number | string) {
  return apiFetch<JournalEntry>(`${API_BASE}/journal-entries/${id}/post`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}
export function reverseJournalEntry(token: string, bizId: string, id: number | string) {
  return apiFetch<JournalEntry>(`${API_BASE}/journal-entries/${id}/reverse`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}

// ─── Financial Reports ────────────────────────────────
export function fetchTrialBalance(token: string, bizId: string, params?: { date_from?: string; date_to?: string }) {
  return apiFetch<TrialBalance>(`${API_BASE}/reports/trial-balance${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchProfitAndLoss(token: string, bizId: string, params?: { date_from?: string; date_to?: string }) {
  return apiFetch<ProfitAndLoss>(`${API_BASE}/reports/profit-and-loss${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchBalanceSheet(token: string, bizId: string, params?: { date_to?: string }) {
  return apiFetch<BalanceSheet>(`${API_BASE}/reports/balance-sheet${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchCashFlow(token: string, bizId: string, params?: { date_from?: string; date_to?: string }) {
  return apiFetch<CashFlow>(`${API_BASE}/reports/cash-flow${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchGeneralLedger(token: string, bizId: string, params: { account_id: number | string; date_from?: string; date_to?: string }) {
  return apiFetch<GeneralLedger>(`${API_BASE}/reports/general-ledger${qs(params)}`, { headers: authHeaders(token, bizId) });
}

export function fetchSalesSummary(token: string, bizId: string, params?: { date_from?: string; date_to?: string }) {
  return apiFetch<SalesSummary>(`${API_BASE}/reports/sales-summary${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
export function fetchStockValuation(token: string, bizId: string) {
  return apiFetch<StockValuation>(`${API_BASE}/reports/stock-valuation`, { headers: authHeaders(token, bizId) });
}
export function fetchSupplierAging(token: string, bizId: string) {
  return apiFetch<SupplierAging>(`${API_BASE}/reports/supplier-aging`, { headers: authHeaders(token, bizId) });
}

// ─── AR / AP Subledgers ───────────────────────────────
export function fetchAccountsReceivable(token: string, bizId: string) {
  return apiFetch<AccountsReceivable>(`${API_BASE}/accounting/ar`, { headers: authHeaders(token, bizId) });
}
export function fetchAccountsPayable(token: string, bizId: string) {
  return apiFetch<AccountsPayable>(`${API_BASE}/accounting/ap`, { headers: authHeaders(token, bizId) });
}
export function fetchPayableStatement(token: string, bizId: string, supplierId: number | string) {
  return apiFetch<PayableStatement>(`${API_BASE}/accounting/ap/${supplierId}/statement`, { headers: authHeaders(token, bizId) });
}
export function collectInvoicePayment(token: string, bizId: string, invoiceId: number | string, data: { amount: number; method: string; reference_number?: string; notes?: string }) {
  return apiFetch<{ payment: Payment; invoice: Invoice }>(`${API_BASE}/accounting/ar/${invoiceId}/pay`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function payPurchaseOrderFromAccounting(token: string, bizId: string, purchaseOrderId: number | string, data: { amount: number; method: string; reference_number?: string; notes?: string }) {
  return apiFetch<PurchaseOrderPayment>(`${API_BASE}/accounting/ap/${purchaseOrderId}/pay`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function payGoodsReceiptFromAccounting(token: string, bizId: string, receiptId: number | string, data: { amount: number; method: string; reference_number?: string; notes?: string }) {
  return apiFetch<PurchaseOrderPayment>(`${API_BASE}/accounting/ap/receipts/${receiptId}/pay`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}

// ─── Fiscal Year Actions ──────────────────────────────
export function closeFiscalYear(token: string, bizId: string, id: number | string) {
  return apiFetch<FiscalYear>(`${API_BASE}/fiscal-years/${id}/close`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}

// ─── Bank Reconciliation Actions ──────────────────────
export function autoMatchReconciliation(token: string, bizId: string, id: number | string) {
  return apiFetch<BankReconciliation>(`${API_BASE}/bank-reconciliations/${id}/auto-match`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}
export function closeReconciliation(token: string, bizId: string, id: number | string) {
  return apiFetch<BankReconciliation>(`${API_BASE}/bank-reconciliations/${id}/close`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}
export function addReconciliationLine(token: string, bizId: string, id: number | string, data: { amount: number; description: string; reference?: string; statement_date: string }) {
  return apiFetch<BankReconciliationLine>(`${API_BASE}/bank-reconciliations/${id}/lines`, {
    method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
  });
}
export function reconcileLine(token: string, bizId: string, reconId: number | string, lineId: number | string) {
  return apiFetch<BankReconciliationLine>(`${API_BASE}/bank-reconciliations/${reconId}/lines/${lineId}/reconcile`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}
export function unreconcileLine(token: string, bizId: string, reconId: number | string, lineId: number | string) {
  return apiFetch<BankReconciliationLine>(`${API_BASE}/bank-reconciliations/${reconId}/lines/${lineId}/unreconcile`, {
    method: "POST", headers: authHeaders(token, bizId),
  });
}
export function importBankStatement(token: string, bizId: string, id: number | string, file: File) {
  const fd = new FormData();
  fd.append("statement", file);
  return apiFetch<BankReconciliation>(`${API_BASE}/bank-reconciliations/${id}/import`, {
    method: "POST", headers: authHeaders(token, bizId), body: fd,
  });
}

// ─── Invoice Actions ──────────────────────────────────
export function payInvoice(token: string, bizId: string, invoiceId: number | string, data: { amount: number; method: string; reference_number?: string; notes?: string }) {
  return apiFetch<{ payment: Payment; invoice: Invoice }>(`${API_BASE}/invoices/${invoiceId}/pay`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}
export function voidInvoice(token: string, bizId: string, invoiceId: number | string) {
  return apiFetch<Invoice>(`${API_BASE}/invoices/${invoiceId}/void`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
  });
}
export function duplicateInvoice(token: string, bizId: string, invoiceId: number | string) {
  return apiFetch<Invoice>(`${API_BASE}/invoices/${invoiceId}/duplicate`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
  });
}

// ─── Status update helpers ─────────────────────────────
export function updateWarrantyClaimStatus(token: string, bizId: string, id: number | string, status: string) {
  return apiFetch<WarrantyClaim>(`${API_BASE}/warranty-claims/${id}/status`, {
    method: "PATCH", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify({ status }),
  });
}
export function updateServiceTicketStatus(token: string, bizId: string, id: number | string, status: string) {
  return apiFetch<ServiceTicket>(`${API_BASE}/service-tickets/${id}/status`, {
    method: "PATCH", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify({ status }),
  });
}

// ─── Barcode Lookup ────────────────────────────────────
export function lookupBarcode(token: string, bizId: string, barcode: string) {
  return apiFetch<Product>(`${API_BASE}/products/barcode/lookup`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify({ barcode }),
  });
}

// ─── Product Bulk Import & Quick-Add ───────────────────
export interface ProductImportResult {
  message: string;
  created: number;
  updated: number;
  rows: number;
}

export async function importProducts(token: string, bizId: string, file: File): Promise<ProductImportResult> {
  const form = new FormData();
  form.append("file", file);
  return apiFetch<ProductImportResult>(`${API_BASE}/products/import`, {
    method: "POST",
    headers: authHeaders(token, bizId),
    body: form,
  });
}

export interface QuickAddPayload {
  name: string;
  barcode?: string | null;
  selling_price: number;
  cost?: number;
  tax_rate?: number | null;
  category?: string | null;
  category_id?: number | null;
  unit?: string | null;
}

export async function quickAddProduct(token: string, bizId: string, payload: QuickAddPayload): Promise<Product> {
  return apiFetch<Product>(`${API_BASE}/products/quick-add`, {
    method: "POST",
    headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

// ─── Supermarket: Promotion Engine ─────────────────────
export function applyPromotions(token: string, bizId: string, data: { items: { product_id: number; quantity: number; unit_price: number }[]; customer_id?: number; local_time?: string }) {
  return apiFetch<PromotionApplyResult>(
    `${API_BASE}/promotions/apply`, {
      method: "POST", headers: { ...authHeaders(token, bizId), "Content-Type": "application/json" }, body: JSON.stringify(data),
    }
  );
}

// ─── Supermarket: Z-Report ────────────────────────────
export function fetchZReports(token: string, bizId: string, params?: Record<string, string | number>) {
  return apiFetch<PaginatedResponse<ZReport>>(`${API_BASE}/z-reports${qs(params || {})}`, { headers: authHeaders(token, bizId) });
}
