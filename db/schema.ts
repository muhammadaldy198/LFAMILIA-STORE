import { sql } from "drizzle-orm";
import { index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

export const products = sqliteTable(
  "products",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    slug: text("slug").notNull(),
    name: text("name").notNull(),
    publisher: text("publisher").notNull().default(""),
    category: text("category").notNull(),
    imageUrl: text("image_url"),
    initials: text("initials").notNull(),
    accent: text("accent").notNull(),
    inputLabel: text("input_label").notNull(),
    inputPlaceholder: text("input_placeholder").notNull(),
    needsServer: integer("needs_server", { mode: "boolean" }).notNull().default(false),
    popular: integer("popular", { mode: "boolean" }).notNull().default(false),
    instant: integer("instant", { mode: "boolean" }).notNull().default(false),
    fulfillmentType: text("fulfillment_type", { enum: ["automatic", "manual"] }).notNull().default("automatic"),
    targetTemplate: text("target_template").notNull().default("{{destination}}{{server}}"),
    manualInstructions: text("manual_instructions"),
    manualOpenTime: text("manual_open_time"),
    manualCloseTime: text("manual_close_time"),
    manualTimezone: text("manual_timezone").notNull().default("Asia/Jakarta"),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("products_slug_unique").on(table.slug),
    index("products_active_sort_idx").on(table.isActive, table.sortOrder),
  ],
);

export const productPackages = sqliteTable(
  "product_packages",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    productId: integer("product_id").notNull().references(() => products.id, { onDelete: "cascade" }),
    sku: text("sku").notNull(),
    label: text("label").notNull(),
    price: integer("price").notNull(),
    note: text("note"),
    providerCode: text("provider_code"),
    providerSku: text("provider_sku"),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("product_packages_sku_unique").on(table.sku),
    index("product_packages_product_sort_idx").on(table.productId, table.isActive, table.sortOrder),
  ],
);

export const productNotices = sqliteTable(
  "product_notices",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    productId: integer("product_id").notNull().references(() => products.id, { onDelete: "cascade" }),
    title: text("title").notNull(),
    body: text("body").notNull(),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("product_notices_product_active_sort_idx").on(table.productId, table.isActive, table.sortOrder)],
);

export const orders = sqliteTable(
  "orders",
  {
    id: text("id").primaryKey(),
    referenceId: text("reference_id").notNull(),
    productSlug: text("product_slug").notNull(),
    productName: text("product_name").notNull(),
    packageSku: text("package_sku").notNull(),
    packageLabel: text("package_label").notNull(),
    providerCode: text("provider_code"),
    providerSku: text("provider_sku"),
    fulfillmentType: text("fulfillment_type", { enum: ["automatic", "manual"] }).notNull(),
    targetTemplate: text("target_template").notNull(),
    destination: text("destination").notNull(),
    server: text("server"),
    nickname: text("nickname"),
    customerNo: text("customer_no"),
    buyerName: text("buyer_name").notNull(),
    buyerEmail: text("buyer_email").notNull(),
    buyerPhone: text("buyer_phone").notNull(),
    customerNotes: text("customer_notes"),
    baseSubtotal: integer("base_subtotal").notNull().default(0),
    subtotal: integer("subtotal").notNull(),
    discountAmount: integer("discount_amount").notNull().default(0),
    voucherCode: text("voucher_code"),
    flashSaleId: integer("flash_sale_id"),
    adminFee: integer("admin_fee").notNull().default(0),
    total: integer("total").notNull(),
    paymentMethod: text("payment_method").notNull(),
    paymentChannel: text("payment_channel").notNull(),
    paymentStatus: text("payment_status").notNull().default("pending"),
    fulfillmentStatus: text("fulfillment_status").notNull().default("waiting_payment"),
    ipaymuTransactionId: text("ipaymu_transaction_id"),
    ipaymuPaymentNo: text("ipaymu_payment_no"),
    ipaymuPaymentName: text("ipaymu_payment_name"),
    ipaymuPaymentUrl: text("ipaymu_payment_url"),
    ipaymuExpiredAt: text("ipaymu_expired_at"),
    providerRefId: text("provider_ref_id"),
    providerStatus: text("provider_status"),
    providerMessage: text("provider_message"),
    providerSerialNumber: text("provider_serial_number"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("orders_reference_id_unique").on(table.referenceId),
    uniqueIndex("orders_provider_ref_id_unique").on(table.providerCode, table.providerRefId),
    index("orders_payment_fulfillment_idx").on(table.paymentStatus, table.fulfillmentStatus),
    index("orders_created_at_idx").on(table.createdAt),
  ],
);

export const orderEvents = sqliteTable(
  "order_events",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    orderId: text("order_id").notNull().references(() => orders.id, { onDelete: "cascade" }),
    source: text("source", { enum: ["ipaymu", "digiflazz", "vippayment", "voucher_stock", "admin"] }).notNull(),
    eventId: text("event_id").notNull(),
    status: text("status").notNull(),
    payloadJson: text("payload_json").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("order_events_source_event_unique").on(table.source, table.eventId),
    index("order_events_order_created_idx").on(table.orderId, table.createdAt),
  ],
);

export const voucherCodes = sqliteTable(
  "voucher_codes",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    stockKey: text("stock_key").notNull(),
    codeCiphertext: text("code_ciphertext").notNull(),
    codeIv: text("code_iv").notNull(),
    codeTag: text("code_tag").notNull(),
    codeHash: text("code_hash").notNull(),
    status: text("status", { enum: ["available", "reserved", "delivered", "void"] }).notNull().default("available"),
    orderId: text("order_id").references(() => orders.id, { onDelete: "set null" }),
    reservedAt: text("reserved_at"),
    deliveredAt: text("delivered_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("voucher_codes_hash_unique").on(table.codeHash),
    uniqueIndex("voucher_codes_order_unique").on(table.orderId),
    index("voucher_codes_stock_status_idx").on(table.stockKey, table.status, table.id),
  ],
);

export const voucherDeliveries = sqliteTable(
  "voucher_deliveries",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    orderId: text("order_id").notNull().references(() => orders.id, { onDelete: "cascade" }),
    voucherCodeId: integer("voucher_code_id").notNull().references(() => voucherCodes.id, { onDelete: "cascade" }),
    channel: text("channel", { enum: ["email", "whatsapp"] }).notNull(),
    status: text("status", { enum: ["pending", "sent", "failed"] }).notNull().default("pending"),
    providerId: text("provider_id"),
    providerMessage: text("provider_message"),
    attempts: integer("attempts").notNull().default(0),
    lastAttemptAt: text("last_attempt_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("voucher_deliveries_order_channel_unique").on(table.orderId, table.channel),
    index("voucher_deliveries_status_updated_idx").on(table.status, table.updatedAt),
  ],
);

export const storeSettings = sqliteTable("store_settings", {
  id: integer("id").primaryKey(),
  storeName: text("store_name").notNull(),
  storeShortName: text("store_short_name").notNull(),
  tagline: text("tagline").notNull(),
  logoUrl: text("logo_url"),
  announcement: text("announcement"),
  bannerEnabled: integer("banner_enabled", { mode: "boolean" }).notNull().default(true),
  bannerEyebrow: text("banner_eyebrow").notNull(),
  bannerTitle: text("banner_title").notNull(),
  bannerHighlight: text("banner_highlight").notNull(),
  bannerDescription: text("banner_description").notNull(),
  bannerImageUrl: text("banner_image_url"),
  bannerCtaLabel: text("banner_cta_label").notNull(),
  bannerCtaHref: text("banner_cta_href").notNull(),
  supportWhatsapp: text("support_whatsapp"),
  supportEmail: text("support_email"),
  instagramUrl: text("instagram_url"),
  supportHours: text("support_hours").notNull(),
  updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const productCategories = sqliteTable(
  "product_categories",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    slug: text("slug").notNull(),
    name: text("name").notNull(),
    icon: text("icon").notNull().default("grid"),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("product_categories_slug_unique").on(table.slug), index("product_categories_active_sort_idx").on(table.isActive, table.sortOrder)],
);

export const discountVouchers = sqliteTable(
  "discount_vouchers",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    code: text("code").notNull(),
    name: text("name").notNull(),
    description: text("description").notNull().default(""),
    discountType: text("discount_type", { enum: ["fixed", "percentage"] }).notNull(),
    discountValue: integer("discount_value").notNull(),
    minPurchase: integer("min_purchase").notNull().default(0),
    maxDiscount: integer("max_discount"),
    usageLimit: integer("usage_limit"),
    usedCount: integer("used_count").notNull().default(0),
    startsAt: text("starts_at").notNull(),
    endsAt: text("ends_at").notNull(),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("discount_vouchers_code_unique").on(table.code), index("discount_vouchers_active_period_idx").on(table.isActive, table.startsAt, table.endsAt)],
);

export const flashSales = sqliteTable(
  "flash_sales",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    productSlug: text("product_slug").notNull(),
    packageSku: text("package_sku").notNull(),
    salePrice: integer("sale_price").notNull(),
    badge: text("badge").notNull().default("Flash Sale"),
    startsAt: text("starts_at").notNull(),
    endsAt: text("ends_at").notNull(),
    stockLimit: integer("stock_limit"),
    soldCount: integer("sold_count").notNull().default(0),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("flash_sales_product_package_idx").on(table.productSlug, table.packageSku), index("flash_sales_active_period_idx").on(table.isActive, table.startsAt, table.endsAt)],
);

export const adminUsers = sqliteTable(
  "admin_users",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    email: text("email").notNull(),
    name: text("name").notNull(),
    role: text("role", { enum: ["owner", "staff"] }).notNull(),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("admin_users_email_unique").on(table.email), index("admin_users_role_active_idx").on(table.role, table.isActive)],
);

export const faqEntries = sqliteTable(
  "faq_entries",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    question: text("question").notNull(),
    answer: text("answer").notNull(),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("faq_entries_active_sort_idx").on(table.isActive, table.sortOrder)],
);
