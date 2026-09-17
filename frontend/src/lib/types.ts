export const CURRENCY_EN = "JOD";
export const CURRENCY_AR = "د.أ";

export function formatCurrency(amount: number | string | undefined | null, locale?: string): string {
  const safe = typeof amount === "number" ? amount : (amount ? Number(amount) : 0);
  const isAr = locale === "ar";
  const symbol = isAr ? CURRENCY_AR : CURRENCY_EN;
  const formatted = safe.toLocaleString(isAr ? "ar-JO" : "en-JO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return isAr ? `${formatted} ${symbol}` : `${symbol} ${formatted}`;
}

export function formatCurrencyShort(amount: number | undefined | null, locale?: string): string {
  const safe = typeof amount === "number" ? amount : 0;
  const isAr = locale === "ar";
  const symbol = isAr ? CURRENCY_AR : CURRENCY_EN;
  return isAr ? `${safe.toFixed(2)} ${symbol}` : `${symbol} ${safe.toFixed(2)}`;
}

export interface TableColumn {
  key: string;
  label: string;
  type: "text" | "currency" | "number" | "date" | "boolean" | "badge";
}

export interface NavigationChild {
  label: string;
  route: string;
  icon: string;
}

export interface NavigationNode {
  label: string;
  route: string;
  icon: string;
  badge?: string;
  children?: NavigationChild[];
}

export interface BootstrapConfig {
  business_id: string;
  business_name: string;
  business_slug: string;
  business_type: string;
  display_name: string;
  logo?: string | null;
  currency: string;
  locale: string;
  rtl: boolean;
  modules: string[];
  settings: Record<string, unknown> | null;
  subscription?: SubscriptionPayload | null;
  navigation: NavigationNode[];
  user_permissions?: string[];
  features: Record<string, boolean>;
  product_table_columns?: TableColumn[];
  dashboard_widgets?: DashboardWidget[];
  theme: {
    primary_color: string;
    sidebar_style: string;
  };
}

export interface DashboardWidget {
  type: string;
  title: string;
  metric?: string;
  endpoint?: string;
}

export interface BusinessTypeItem {
  id: number;
  slug: string;
  name_en: string;
  name_ar: string;
  allowed_modules: string[];
}

export interface BusinessSettings {
  allow_split_payments: boolean;
  allow_credit_sales: boolean;
  sales_invoice_prefix: string;
  purchase_order_prefix: string;
  grn_prefix: string;
  invoice_footer_terms: string | null;
  tax_enabled: boolean;
  default_tax_rate: number;
  tax_calculation_method: "inclusive" | "exclusive";
  jofotara_enabled: boolean;
  jofotara_client_id: string | null;
  jofotara_secret_key: string | null;
  loyalty_enabled: boolean;
  loyalty_earn_rate: number;
  loyalty_redemption_rate: number;
  loyalty_alert_threshold: number;
  tax_number: string | null;
  phone: string | null;
  address: string | null;
  auto_print_receipt: boolean;
  receipt_paper_width: "80mm" | "58mm";
  receipt_footer_message: string | null;
  allow_negative_stock: boolean;
  scale_barcode_parsing: boolean;
  scale_barcode_prefix: string | null;
  expiry_warning_days: number | null;
  [key: string]: unknown;
}

export interface DocumentNumbers {
  sales_invoice: string;
  purchase_order: string;
  grn: string;
}

export interface BusinessSettingsResponse {
  settings: BusinessSettings;
  next_numbers: DocumentNumbers;
  logo?: string | null;
}

export interface BusinessSettingsUpdateResponse extends BusinessSettingsResponse {
  business_name: string;
  user: { name: string; email: string };
}

export interface Category {
  id: number;
  name: string;
  name_ar: string | null;
  color?: string | null;
  parent_id?: number | null;
  sort_order?: number | null;
  linked_products_count?: number;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  username: string;
  role: string;
  avatar?: string | null;
  business_id?: string;
  is_active?: boolean;
  is_primary_admin?: boolean;
  is_platform_owner?: boolean;
}

export interface SubscriptionPayload {
  plan: string;
  status: string;
  starts_at: string | null;
  expires_at: string | null;
  days_remaining: number | null;
  state: "active" | "expiring_soon" | "expired" | "suspended";
}

export interface AuthBusiness {
  id: string;
  name: string;
  slug: string;
  status: string;
  business_type: {
    id: number;
    slug: string;
    name_en: string;
    name_ar: string;
  };
  subscription?: SubscriptionPayload | null;
}

export interface LoginResponse {
  token: string;
  user: AuthUser;
  business: AuthBusiness | null;
}

export interface RegisterResponse {
  business: AuthBusiness;
  user: AuthUser;
  token: string;
}

export interface ActivationPreview {
  token: string;
  expires_at: string;
  business: {
    name: string;
    slug: string | null;
    business_type: { slug: string; name_en: string; name_ar: string } | null;
    subdomain: string | null;
    domain: string | null;
    city: string | null;
    contact: { name: string | null; email: string | null; phone: string | null };
  };
}

export interface ActivateResponse {
  ok: boolean;
  message: string;
  business: { id: string; name: string; slug: string };
  onboarding: {
    username: string;
    subdomain: string | null;
    domain: string | null;
    login_url: string;
  };
}

export interface TenantLoginResponse extends LoginResponse {
  needs_activation?: boolean;
  business_name?: string;
  activation_url?: string | null;
  subdomain?: string | null;
}

export interface LeadPayload {
  name: string;
  business_name: string;
  business_type_id?: number | null;
  phone: string;
  city?: string | null;
}

export interface PlatformTenant {
  id: string;
  name: string;
  slug: string;
  status: string;
  plan: string;
  subdomain: string | null;
  domain: string | null;
  database: string | null;
  store_url: string | null;
  owner_contact: { name: string | null; email: string | null; phone: string | null };
  city: string | null;
  business_type: { id: number; slug: string; name_en: string; name_ar: string } | null;
  pos_terminals: { used: number; max: number | null };
  users_count: number;
  subscription: SubscriptionPayload;
  created_at: string | null;
}

export interface PlatformTenantsResponse {
  summary: {
    total: number;
    active: number;
    suspended: number;
    expired: number;
    expiring_soon: number;
  };
  tenants: PlatformTenant[];
}

export interface PlatformTenantCreatePayload {
  name: string;
  business_type_id: number;
  plan?: string;
  contact_phone?: string;
  city?: string;
  subscription_starts_at?: string;
  expires_at?: string;
  max_pos_registers?: number;
  status?: string;
  admin_name: string;
  admin_username: string;
  admin_email: string;
  admin_password: string;
  admin_password_confirmation: string;
}

export interface PlatformTenantUpdatePayload {
  name?: string;
  plan?: string;
  status?: string;
  contact_phone?: string | null;
  city?: string | null;
  subscription_starts_at?: string | null;
  expires_at?: string | null;
  max_pos_registers?: number | null;
  admin_name?: string;
  admin_email?: string;
  admin_password?: string;
  admin_password_confirmation?: string;
}

export interface PlatformLead {
  id: number;
  name: string;
  business_name: string;
  business_type_id: number | null;
  phone: string;
  email: string | null;
  subdomain: string | null;
  city: string | null;
  status: "new" | "provisioned" | "rejected";
  tenant_business_id: string | null;
  approved_by: number | null;
  provisioned_at: string | null;
  metadata: Record<string, unknown> | null;
  business_type: { id: number; slug: string; name_en: string; name_ar: string } | null;
  created_at: string | null;
}

export interface PlatformLeadsResponse {
  summary: { total: number; new: number; provisioned: number };
  leads: PlatformLead[];
}

export interface PlatformLeadApprovePayload {
  subdomain?: string;
  email?: string;
  subscription_days?: number;
  plan?: string;
}

export interface ApproveLeadResponse {
  message: string;
  lead: PlatformLead;
  domain: string;
  activation_url: string;
  business_id: string;
  plan?: string;
  database?: string | null;
  store_url?: string | null;
}

export interface RejectLeadResponse {
  message: string;
  lead: PlatformLead;
}

export interface DashboardStats {
  products: { total: number; active: number };
  invoices: { total: number; revenue: number; paid: number; unpaid: number; gross_profit: number; gross_margin: number; cogs: number };
  today: { transactions: number; revenue: number };
  recent_invoices: {
    id: number;
    invoice_number: string;
    net_amount: number;
    payment_status: string;
    created_by: string;
    created_at: string;
  }[];
}

export interface SupermarketDashboardStats {
  generated_at: string;
  today: {
    revenue: number;
    change: number;
    yesterday_revenue: number;
    gross_profit: number;
    gross_margin: number;
    transactions: number;
    average_ticket: number;
  };
  inventory: {
    expiring_count: number;
    low_stock_count: number;
  };
  hourly_sales: { hour: number; sales: number }[];
  sales_by_category: { category: string; revenue: number; quantity: number }[];
  payment_breakdown: {
    cash: number;
    card: number;
    bank_transfer: number;
    check: number;
    mobile: number;
    credit: number;
  };
  expiry_alerts: {
    batch_id: number;
    product_id: number;
    product_name: string;
    batch_number: string;
    expiry_date: string;
    days_remaining: number;
    current_stock: number;
  }[];
  low_stock: {
    product_id: number;
    product_name: string;
    sku: string;
    current_stock: number;
    min_stock: number;
  }[];
  top_products: {
    product_id: number;
    name: string;
    image_url?: string | null;
    unit?: string | null;
    quantity: number;
    revenue: number;
  }[];
  active_shift: {
    id: number;
    shift_number: string;
    cashier: string;
    started_at: string;
    opening_balance: number;
    expected_cash: number;
    total_sales: number;
    total_transactions: number;
    cash_refunds: number;
  } | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  first_page_url: string;
  last_page_url: string;
  next_page_url: string | null;
  prev_page_url: string | null;
  counters?: { out_of_stock: number; low_stock: number; total: number };
  reorder_cost?: number;
  value_at_risk?: number;
}

export interface Product {
  id: number;
  business_id: string;
  name: string;
  sku: string | null;
  barcode: string | null;
  description: string | null;
  unit: string;
  purchase_unit?: string | null;
  purchase_unit_qty?: number | null;
  cost: number;
  price: number;
  sale_price: number | null;
  is_on_sale: boolean;
  effective_price: number;
  tax_rate: number;
  category: string | null;
  has_expiry: boolean;
  has_batch: boolean;
  min_stock: number;
  stock_quantity: number;
  preferred_supplier_id?: number | null;
  storage_location: string | null;
  is_weighable: boolean;
  is_active: boolean;
  is_composite: boolean;
  track_inventory: boolean;
  image_url: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  variants?: ProductVariant[];
  batches?: ProductBatch[];
  serial_numbers?: SerialNumber[];
  category_id?: number | null;
  unit_id?: number | null;
  tax_id?: number | null;
  scale_weight?: number;
}

export interface ProductVariant {
  id: number;
  business_id: string;
  product_id: number;
  sku: string;
  barcode: string | null;
  attribute1_name: string | null;
  attribute1_value: string | null;
  attribute2_name: string | null;
  attribute2_value: string | null;
  price_adjustment: number | null;
  cost_adjustment: number | null;
  stock_quantity: number;
  is_active: boolean;
  metadata: Record<string, unknown> | null;
  product?: Product;
}

export interface Collection {
  id: number;
  business_id: string;
  name: string;
  season: string | null;
  year: string | null;
  description: string | null;
  start_date: string | null;
  end_date: string | null;
  is_active: boolean;
  products_count?: number;
  created_at: string;
  updated_at: string;
}

export interface ProductBatch {
  id: number;
  business_id: string;
  product_id: number;
  batch_number: string;
  source_type: string | null;
  quantity: number;
  quantity_sold: number;
  quantity_returned: number;
  supplier_id: number | null;
  goods_receipt_id: number | null;
  received_date: string | null;
  expiry_date: string | null;
  manufacturing_date: string | null;
  total_cost: number | null;
  cost_per_unit: number | null;
  selling_price: number | null;
  storage_location: string | null;
  is_active: boolean;
  metadata: Record<string, unknown> | null;
  product?: Product;
  supplier?: Supplier;
  goods_receipt?: GoodsReceipt;
}

export interface SerialNumber {
  id: number;
  business_id: string;
  product_id: number;
  serial_number: string;
  imei: string | null;
  warranty_start: string | null;
  warranty_end: string | null;
  warranty_status: string;
  status: string;
  customer_id: number | null;
  sold_at: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  product?: Product;
  customer?: Customer;
}

export interface Customer {
  id: number;
  business_id: string;
  type: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  delivery_notes: string | null;
  notes: string | null;
  loyalty_card_number: string | null;
  loyalty_points_balance: number;
  tier_level: string;
  is_vip: boolean;
  total_spend: number;
  total_visits: number;
  last_visit_date: string | null;
  size_top?: string | null;
  size_bottom?: string | null;
  shoe_size?: string | null;
  fit_preference?: string | null;
  preferred_brands?: string[] | null;
  loyalty_card_id: number | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  loyalty_card?: LoyaltyCard;
}

export interface Supplier {
  id: number;
  business_id: string;
  name: string;
  email: string | null;
  phone: string | null;
  tax_number: string | null;
  address: string | null;
  contact_name: string | null;
  payment_terms: string | null;
  is_active: boolean;
  balance?: number;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface SupplierProduct {
  id: number;
  business_id: string;
  supplier_id: number;
  product_id: number | null;
  name: string;
  catalog_cost: number | null;
  is_imported: boolean;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  product?: {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    is_weighable: boolean;
    is_active: boolean;
  } | null;
}

export interface Invoice {
  id: number;
  business_id: string;
  user_id: number;
  customer_id: number | null;
  invoice_number: string;
  status: string;
  total_amount: number;
  tax_amount: number;
  discount_amount: number;
  net_amount: number;
  subtotal: number;
  shipping_amount: number;
  payment_status: string;
  due_date: string | null;
  currency: string;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  sent_at: string | null;
  voided_at: string | null;
  created_at: string;
  updated_at: string;
  customer?: Customer;
  items?: InvoiceItem[];
  payments?: Payment[];
}

export interface Payment {
  id: number;
  business_id: string;
  user_id: number;
  invoice_id: number | null;
  customer_id: number | null;
  payment_number: string;
  amount: number;
  method: string;
  reference_number: string | null;
  status: string;
  refunded_amount: number;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  invoice?: Invoice;
  customer?: Customer;
}

export interface CustomerStatementLine {
  date: string;
  reference: string;
  type: 'invoice' | 'payment';
  description: string;
  debit: number;
  credit: number;
  balance: number;
}

export interface CustomerStatement {
  customer: { id: number; name: string };
  date_from: string | null;
  date_to: string | null;
  account_code: string;
  account_name: string;
  lines: CustomerStatementLine[];
  total_debit: number;
  total_credit: number;
  balance: number;
}

export interface InvoiceItem {
  id: number;
  business_id: string;
  invoice_id: number;
  product_id: number;
  name: string;
  quantity: number;
  unit_price: number;
  discount: number;
  tax_rate: number;
  tax_amount: number;
  total: number;
  metadata: Record<string, unknown> | null;
  product?: Product;
}

export interface PurchaseOrder {
  id: number;
  business_id: string;
  user_id: number;
  supplier_id: number;
  order_number: string;
  status: string;
  total_amount: number;
  tax_amount: number;
  paid_amount?: number;
  remaining_amount?: number;
  expected_delivery: string | null;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  supplier?: Supplier;
  items?: PurchaseOrderItem[];
  payments?: PurchaseOrderPayment[];
}

export interface PurchaseOrderItem {
  id: number;
  business_id: string;
  purchase_order_id: number;
  product_id: number;
  name: string;
  quantity: number;
  unit_cost: number;
  total: number;
  received_quantity: number;
  metadata: Record<string, unknown> | null;
}

export interface PurchaseOrderPayment {
  id: number;
  business_id: string;
  purchase_order_id: number;
  supplier_id: number | null;
  payment_number: string;
  amount: number;
  method: string;
  reference_number: string | null;
  status: string;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  purchaseOrder?: PurchaseOrder;
  supplier?: Supplier;
}

export interface Account {
  id: number;
  business_id: string;
  code: string;
  name: string;
  type: string;
  parent_id?: number | null;
  balance?: number;
  is_active: boolean;
  metadata: Record<string, unknown> | null;
  parent?: { id: number; code: string; name: string; type: string } | null;
}

export interface JournalEntry {
  id: number;
  business_id: string;
  user_id: number;
  entry_number: string;
  date: string;
  description: string;
  is_posted: boolean;
  total_debit?: number;
  total_credit?: number;
  metadata: Record<string, unknown> | null;
  created_at: string;
  lines?: JournalEntryLine[];
}

export interface JournalEntryLine {
  id: number;
  business_id: string;
  journal_entry_id: number;
  account_id: number;
  description: string;
  debit: number;
  credit: number;
  metadata: Record<string, unknown> | null;
  account?: Account;
}

export interface FiscalYear {
  id: number;
  business_id: string;
  name: string;
  start_date: string;
  end_date: string;
  is_closed: boolean;
  closed_at: string | null;
  closed_by: number | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface BankReconciliation {
  id: number;
  business_id: string;
  account_id: number;
  statement_date: string;
  statement_balance: number;
  book_balance: number;
  status: string;
  reconciled_at: string | null;
  reconciled_by: number | null;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  account?: Account;
  reconciler?: { id: number; name: string };
  lines?: BankReconciliationLine[];
}

export interface BankReconciliationLine {
  id: number;
  business_id: string;
  bank_reconciliation_id: number;
  journal_entry_line_id: number | null;
  amount: number;
  description: string | null;
  reference: string | null;
  statement_date: string | null;
  reconciled: boolean;
  reconciled_at: string | null;
  journalEntryLine?: JournalEntryLine;
}

export interface TrialBalanceAccount {
  account_id: number;
  code: string;
  name: string;
  type: string;
  debit: number;
  credit: number;
  balance: number;
}

export interface TrialBalance {
  date_from: string | null;
  date_to: string;
  accounts: TrialBalanceAccount[];
  total_debit: number;
  total_credit: number;
  is_balanced: boolean;
}

export interface ProfitAndLoss {
  date_from: string;
  date_to: string;
  revenue: { accounts: { code: string; name: string; type: string; debit: number; credit: number; balance: number }[]; total: number };
  expense: { accounts: { code: string; name: string; type: string; debit: number; credit: number; balance: number }[]; total: number };
  net_income: number;
}

export interface BalanceSheet {
  date_to: string;
  asset: { accounts: { code: string; name: string; type: string; debit: number; credit: number; balance: number }[]; total: number };
  liability: { accounts: { code: string; name: string; type: string; debit: number; credit: number; balance: number }[]; total: number };
  equity: { accounts: { code: string; name: string; type: string; debit: number; credit: number; balance: number }[]; total: number; retained_earnings: number };
  total_liabilities_equity: number;
  is_balanced: boolean;
}

export interface CashFlow {
  date_from: string;
  date_to: string;
  operating: number;
  investing: number;
  financing: number;
  net_cash_flow: number;
}

export interface SalesSummaryItem {
  product_id: number | null;
  name: string;
  sku: string | null;
  category: string | null;
  quantity: number;
  revenue: number;
  cogs: number;
  profit: number;
  margin: number;
}

export interface SalesCategorySummary {
  category: string | null;
  quantity: number;
  revenue: number;
  cogs: number;
  profit: number;
  margin: number;
}

export interface SalesSummary {
  date_from: string | null;
  date_to: string;
  summary: {
    invoice_count: number;
    total_quantity: number;
    total_revenue: number;
    net_revenue: number;
    tax_amount: number;
    gross_revenue: number;
    total_cogs: number;
    gross_profit: number;
    gross_margin: number;
  };
  by_product: SalesSummaryItem[];
  by_category: SalesCategorySummary[];
}

export interface StockValuationProduct {
  product_id: number;
  name: string;
  sku: string | null;
  unit: string;
  category: string | null;
  quantity: number;
  cost_per_unit: number;
  value: number;
}

export interface StockValuation {
  generated_at: string;
  summary: { product_count: number; total_units: number; total_value: number };
  products: StockValuationProduct[];
}

export interface SupplierAgingOrder {
  id: number;
  order_number: string;
  order_date: string;
  due_date: string | null;
  status: string;
  total: number;
  paid: number;
  balance: number;
  days_past_due: number;
}

export interface SupplierAgingRow {
  supplier_id: number;
  name: string;
  phone: string | null;
  orders_count: number;
  outstanding: number;
  buckets: { current: number; d30: number; d60: number; d90: number };
  orders: SupplierAgingOrder[];
}

export interface SupplierAging {
  as_of: string;
  total_outstanding: number;
  suppliers: SupplierAgingRow[];
}

export interface AccountReceivableInvoice {
  id: number;
  invoice_number: string;
  date: string;
  payment_status: string;
  net_amount: number;
  paid: number;
  balance: number;
}

export interface AccountReceivableCustomer {
  customer_id: number;
  name: string;
  phone: string | null;
  email: string | null;
  open_invoices_count: number;
  outstanding: number;
  invoices: AccountReceivableInvoice[];
}

export interface AccountsReceivable {
  as_of: string;
  account_code: string;
  account_name: string;
  total_outstanding: number;
  customers: AccountReceivableCustomer[];
}

export interface AccountPayableOrder {
  id: number;
  order_number: string;
  order_date: string;
  due_date: string | null;
  status: string;
  total: number;
  paid: number;
  balance: number;
}

export interface AccountPayableReceipt {
  id: number;
  receipt_number: string;
  receipt_date: string;
  status: string;
  total: number;
  paid: number;
  balance: number;
}

export interface AccountPayableSupplier {
  supplier_id: number;
  name: string;
  phone: string | null;
  open_orders_count: number;
  open_receipts_count: number;
  returns_total: number;
  outstanding: number;
  orders: AccountPayableOrder[];
  receipts: AccountPayableReceipt[];
}

export interface AccountsPayable {
  as_of: string;
  account_code: string;
  account_name: string;
  total_outstanding: number;
  suppliers: AccountPayableSupplier[];
}

export interface PayableStatementPayment {
  payment_number: string;
  date: string;
  amount: number;
  method: string;
}

export interface PayableStatementOrder {
  id: number | null;
  kind?: string;
  order_number?: string;
  receipt_number?: string;
  order_date: string | null;
  due_date?: string | null;
  status?: string;
  total: number;
  paid: number;
  balance: number;
  payments: PayableStatementPayment[];
}

export interface PayableStatement {
  supplier: { id: number; name: string; phone: string | null };
  account_code: string;
  account_name: string;
  total_outstanding: number;
  orders: PayableStatementOrder[];
}

export interface GeneralLedgerEntry {
  id: number;
  date: string;
  entry_number: string;
  description: string;
  debit: number;
  credit: number;
  balance: number;
}

export interface GeneralLedger {
  account: { id: number; code: string; name: string; type: string };
  date_from: string | null;
  date_to: string | null;
  entries: GeneralLedgerEntry[];
}

export interface LoyaltyCard {
  id: number;
  business_id: string;
  customer_id: number;
  card_number: string;
  points_balance: number;
  total_points_earned: number;
  total_points_redeemed: number;
  total_spend: number;
  tier: string;
  is_active: boolean;
  tier_upgraded_at: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  customer?: Customer;
}

export interface LoyaltyTransaction {
  id: number;
  business_id: string;
  loyalty_card_id: number;
  type: string;
  points: number;
  balance_after: number | null;
  description: string | null;
  invoice_id: number | null;
  points_expiry_date: string | null;
  created_at: string;
  loyaltyCard?: LoyaltyCard;
}

export interface Campaign {
  id: number;
  business_id: string;
  name: string;
  segment_type: "lost" | "vip" | "all" | "tier";
  channel: "sms" | "whatsapp";
  message_template: string;
  status: "draft" | "sending" | "completed" | "failed";
  recipients_count: number;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  sent_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  messages_count?: number;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface CampaignStats {
  campaign_id: number;
  status: "draft" | "sending" | "completed" | "failed";
  recipients_count: number;
  messages: {
    total: number;
    sent: number;
    delivered: number;
    failed: number;
    queued: number;
  };
  status_breakdown: Record<string, number>;
  started_at: string | null;
  completed_at: string | null;
  sent_at: string | null;
}

export interface CustomerSegments {
  total: number;
  active: number;
  new: number;
  lost: number;
  vip: number;
  vip_spend_threshold: number;
  lost_list: Customer[];
  vip_list: Customer[];
}

export interface PromotionStats {
  times_used: number;
  total_revenue: number;
  total_discount: number;
  effectiveness: "high_impact" | "low_impact" | "negative_margin";
}

export interface Promotion {
  id: number;
  business_id: string;
  name: string;
  type: string;
  value: number;
  start_date: string;
  end_date: string;
  min_quantity: number;
  min_amount: number;
  buy_quantity: number | null;
  get_quantity: number | null;
  discount_value: number | null;
  max_uses: number | null;
  current_uses: number;
  combo_products: number[] | null;
  happy_hour_start: string | null;
  happy_hour_end: string | null;
  applicable_products: number[] | null;
  category_id: number | null;
  is_active: boolean;
  metadata: Record<string, unknown> | null;
  stats?: PromotionStats;
  created_at: string;
}

export interface AppliedPromotion {
  id: number;
  name: string;
  type: string;
  discount: number;
}

export interface PromotionApplyResult {
  applied_promotions: AppliedPromotion[];
  total_discount: number;
  cart_subtotal: number;
  cart_total: number;
}

export type PromotionType = 'percentage' | 'fixed' | 'bogo' | 'bundle' | 'multi_buy' | 'category' | 'happy_hour';

export interface Shift {
  id: number;
  business_id: string;
  user_id: number;
  shift_number: string;
  opening_balance: number;
  closing_balance: number | null;
  expected_cash: number | null;
  actual_cash: number | null;
  variance: number | null;
  total_sales: number;
  total_refunds: number;
  total_discounts: number;
  total_transactions: number;
  payment_breakdown: Record<string, number> | null;
  status: string;
  started_at: string;
  ended_at: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  user?: AuthUser;
  cashier?: string;
}

export interface ZReport {
  id: number;
  business_id: string;
  shift_id: number;
  user_id: number;
  report_number: string;
  started_at: string;
  ended_at: string | null;
  opening_balance: number;
  closing_balance: number | null;
  expected_cash: number | null;
  actual_cash: number | null;
  variance: number | null;
  total_sales: number;
  total_refunds: number;
  total_discounts: number;
  total_tax: number;
  total_transactions: number;
  payment_breakdown: Record<string, number> | null;
  top_products: { product_id: number; name: string; quantity: number; total: number }[] | null;
  summary: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  shift?: Shift;
  user?: AuthUser;
}

export interface InventoryAdjustment {
  id: number;
  business_id: string;
  user_id: number;
  product_id: number;
  batch_id: number | null;
  adjustment_number: string;
  type: string;
  liability_type: string | null;
  supplier_id: number | null;
  purchase_return_id: number | null;
  quantity_before: number;
  quantity_adjusted: number;
  quantity_after: number;
  unit_cost: number | null;
  reason: string | null;
  notes: string | null;
  status: string;
  metadata: Record<string, unknown> | null;
  created_at: string;
  product?: Product;
  batch?: ProductBatch;
  supplier?: Supplier;
  user?: AuthUser;
  journal_entries?: JournalEntry[];
}

export interface WarrantyClaim {
  id: number;
  business_id: string;
  serial_number_id: number;
  customer_id: number;
  issue_description: string;
  status: string;
  repair_cost: number | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  serial_number?: SerialNumber;
  customer?: Customer;
}

export interface ServiceTicket {
  id: number;
  business_id: string;
  ticket_number: string;
  customer_id: number;
  device_name: string;
  device_serial: string | null;
  issue_description: string;
  status: string;
  priority: string;
  technician: string | null;
  estimated_cost: number | null;
  final_cost: number | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  customer?: Customer;
}

export interface Recipe {
  id: number;
  business_id: string;
  product_id: number;
  name: string;
  serving_size: string | null;
  instructions: string | null;
  metadata: Record<string, unknown> | null;
  product?: Product;
  ingredients?: RecipeIngredient[];
}

export interface RecipeIngredient {
  id: number;
  business_id: string;
  recipe_id: number;
  product_id: number;
  quantity: number;
  unit: string;
  cost_per_unit: number | null;
  metadata: Record<string, unknown> | null;
  product?: Product;
}

export interface Warehouse {
  id: number;
  name: string;
  code: string;
  location: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface StockMovement {
  id: number;
  product_id: number;
  from_warehouse_id: number | null;
  to_warehouse_id: number | null;
  quantity: number;
  type: string;
  reference_type: string | null;
  reference_id: number | null;
  notes: string | null;
  created_at: string;
  product?: Product;
  from_warehouse?: Warehouse;
  to_warehouse?: Warehouse;
}

export interface GoodsReceiptItem {
  id: number;
  business_id: string;
  goods_receipt_id: number;
  product_id: number | null;
  batch_id: number | null;
  name: string;
  quantity: number;
  unit_cost: number;
  total: number;
  purchase_quantity: number | null;
  purchase_unit_qty: number | null;
  expiry_date: string | null;
  storage_location: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  product?: Product;
  batch?: ProductBatch;
}

export interface GoodsReceipt {
  id: number;
  business_id: string;
  purchase_order_id: number | null;
  supplier_id: number | null;
  user_id: number | null;
  receipt_number: string;
  reference_invoice_number: string | null;
  notes: string | null;
  received_at: string;
  payment_method: string | null;
  total_amount: number | null;
  pay_now_amount?: number | null;
  payable_credit?: number | null;
  status: string | null;
  created_at: string;
  purchase_order?: PurchaseOrder;
  supplier?: Supplier;
  user?: { id: number; name: string };
  items?: GoodsReceiptItem[];
}

export interface SupplierLedgerRow {
  kind: "purchase_order" | "goods_receipt" | "payment" | "purchase_return" | "supplier_claim";
  date: string | null;
  reference: string;
  detail: string;
  debit: number;
  credit: number;
  balance: number;
}

export interface SupplierLedger {
  supplier: { id: number; name: string; phone: string | null };
  balance: number;
  rows: SupplierLedgerRow[];
}

export const RBAC_ACTIONS = ["view", "create", "edit", "delete"] as const;
export type RbacAction = (typeof RBAC_ACTIONS)[number];

export interface RbacModule {
  key: string;
  label: string;
}

export interface Role {
  id: string;
  business_id: string;
  name: string;
  slug: string;
  description: string | null;
  permissions: string[];
  is_system: boolean;
  created_at: string;
  updated_at: string;
}

export interface RoleMatrix {
  modules: RbacModule[];
  actions: { key: string; label: string }[];
  roles: Role[];
}

export type PermissionMap = Record<string, string[]>;

export interface ReturnExchangeItem {
  id: number;
  return_exchange_id: number;
  invoice_item_id: number;
  product_id: number;
  batch_id: number | null;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  reason: string | null;
  is_exchange: boolean;
  product?: Pick<Product, "id" | "name" | "sku">;
  invoice_item?: Pick<InvoiceItem, "id" | "name" | "unit_price" | "quantity">;
}

export interface ReturnExchange {
  id: number;
  business_id: string;
  user_id: number;
  invoice_id: number;
  return_number: string;
  type: "return" | "exchange";
  status: string;
  refund_method: string | null;
  returned_amount: number;
  exchanged_amount: number;
  difference_amount: number;
  refund_amount: number;
  exchange_invoice_id: number | null;
  notes: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  invoice?: Pick<Invoice, "id" | "invoice_number" | "customer_id" | "net_amount"> & {
    customer?: Pick<Customer, "id" | "name">;
  };
  user?: Pick<AuthUser, "id" | "name">;
  items?: ReturnExchangeItem[];
  exchange_invoice?: Pick<Invoice, "id" | "invoice_number" | "net_amount">;
}

export interface ReturnableItem {
  invoice_item_id: number;
  product_id: number | null;
  name: string;
  sku: string | null;
  unit: string | null;
  unit_price: number;
  tax_rate: number;
  quantity: number;
  already_returned: number;
  returnable: number;
  batch_id: number | null;
  has_batch: boolean;
}

export interface ReturnablePreview {
  invoice: {
    id: number;
    invoice_number: string;
    customer_id: number | null;
    customer_name: string | null;
    net_amount: number;
  };
  items: ReturnableItem[];
}
