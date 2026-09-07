import { sql } from "drizzle-orm";
import { index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

export const customerUsers = sqliteTable(
  "customer_users",
  {
    id: text("id").primaryKey(),
    email: text("email").notNull(),
    name: text("name").notNull(),
    phone: text("phone").notNull(),
    passwordHash: text("password_hash").notNull(),
    passwordSalt: text("password_salt").notNull(),
    balance: integer("balance").notNull().default(0),
    tierMode: text("tier_mode").notNull().default("automatic"),
    tierOverride: text("tier_override"),
    tierProgressBonus: integer("tier_progress_bonus").notNull().default(0),
    leaderboardOptIn: integer("leaderboard_opt_in", { mode: "boolean" }).notNull().default(false),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    lastLoginAt: text("last_login_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("customer_users_email_unique").on(table.email),
    index("customer_users_leaderboard_idx").on(table.leaderboardOptIn, table.isActive),
  ],
);

export const customerSessions = sqliteTable(
  "customer_sessions",
  {
    id: text("id").primaryKey(),
    customerId: text("customer_id").notNull().references(() => customerUsers.id, { onDelete: "cascade" }),
    tokenHash: text("token_hash").notNull(),
    expiresAt: text("expires_at").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    uniqueIndex("customer_sessions_token_unique").on(table.tokenHash),
    index("customer_sessions_customer_expiry_idx").on(table.customerId, table.expiresAt),
  ],
);

export const customerGameAccounts = sqliteTable(
  "customer_game_accounts",
  {
    id: text("id").primaryKey(),
    customerId: text("customer_id").notNull().references(() => customerUsers.id, { onDelete: "cascade" }),
    productSlug: text("product_slug").notNull(),
    label: text("label").notNull(),
    valuesJson: text("values_json").notNull().default("[]"),
    nickname: text("nickname"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    index("customer_game_accounts_customer_product_idx").on(table.customerId, table.productSlug, table.updatedAt),
  ],
);

export const products = sqliteTable(
  "products",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    slug: text("slug").notNull(),
    name: text("name").notNull(),
    publisher: text("publisher").notNull().default(""),
    category: text("category").notNull(),
    imageUrl: text("image_url"),
    bannerUrl: text("banner_url"),
    description: text("description"),
    initials: text("initials").notNull(),
    accent: text("accent").notNull(),
    inputLabel: text("input_label").notNull(),
    inputPlaceholder: text("input_placeholder").notNull(),
    inputFieldsJson: text("input_fields_json"),
    needsServer: integer("needs_server", { mode: "boolean" }).notNull().default(false),
    popular: integer("popular", { mode: "boolean" }).notNull().default(false),
    instant: integer("instant", { mode: "boolean" }).notNull().default(false),
    fulfillmentType: text("fulfillment_type", { enum: ["automatic", "manual"] }).notNull().default("automatic"),
    targetTemplate: text("target_template").notNull().default("{{destination}}{{server}}"),
    manualInstructions: text("manual_instructions"),
    manualOpenTime: text("manual_open_time"),
    manualCloseTime: text("manual_close_time"),
    manualTimezone: text("manual_timezone").notNull().default("Asia/Jakarta"),
    packageTabsEnabled: integer("package_tabs_enabled", { mode: "boolean" }).notNull().default(false),
    packageTabsJson: text("package_tabs_json").notNull().default("[]"),
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
    packageGroup: text("package_group"),
    providerCode: text("provider_code"),
    providerSku: text("provider_sku"),
    supplierPrice: integer("supplier_price"),
    pricingMode: text("pricing_mode", { enum: ["manual", "auto"] }).notNull().default("manual"),
    marginType: text("margin_type", { enum: ["fixed", "percent"] }).notNull().default("fixed"),
    marginValue: integer("margin_value").notNull().default(0),
    supplierSyncedAt: text("supplier_synced_at"),
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
    customerId: text("customer_id").references(() => customerUsers.id, { onDelete: "set null" }),
    walletCheckoutKey: text("wallet_checkout_key"),
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
    customerInputsJson: text("customer_inputs_json").notNull().default("[]"),
    baseSubtotal: integer("base_subtotal").notNull().default(0),
    subtotal: integer("subtotal").notNull(),
    discountAmount: integer("discount_amount").notNull().default(0),
    voucherCode: text("voucher_code"),
    voucherId: integer("voucher_id"),
    flashSaleId: integer("flash_sale_id"),
    promotionReservationStatus: text("promotion_reservation_status", {
      enum: ["none", "legacy", "reserved", "consumed", "released"],
    }).notNull().default("legacy"),
    promotionReservedUntil: text("promotion_reserved_until"),
    adminFee: integer("admin_fee").notNull().default(0),
    total: integer("total").notNull(),
    paymentMethod: text("payment_method").notNull(),
    paymentChannel: text("payment_channel").notNull(),
    paymentStatus: text("payment_status").notNull().default("pending"),
    fulfillmentStatus: text("fulfillment_status").notNull().default("waiting_payment"),
    midtransTransactionId: text("midtrans_transaction_id"),
    midtransPaymentNo: text("midtrans_payment_no"),
    midtransPaymentName: text("midtrans_payment_name"),
    midtransPaymentUrl: text("midtrans_payment_url"),
    midtransExpiredAt: text("midtrans_expired_at"),
    midtransMode: text("midtrans_mode", { enum: ["snap", "bisnap"] }),
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
    uniqueIndex("orders_wallet_checkout_key_unique").on(table.customerId, table.walletCheckoutKey),
    uniqueIndex("orders_provider_ref_id_unique").on(table.providerCode, table.providerRefId),
    index("orders_payment_fulfillment_idx").on(table.paymentStatus, table.fulfillmentStatus),
    index("orders_created_at_idx").on(table.createdAt),
    index("orders_customer_created_idx").on(table.customerId, table.createdAt),
  ],
);

export const orderEvents = sqliteTable(
  "order_events",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    orderId: text("order_id").notNull().references(() => orders.id, { onDelete: "cascade" }),
    source: text("source", { enum: ["midtrans", "ipaymu", "wallet", "digiflazz", "vippayment", "voucher_stock", "admin"] }).notNull(),
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
  discordUrl: text("discord_url"),
  supportHours: text("support_hours").notNull(),
  supportWidgetEnabled: integer("support_widget_enabled", { mode: "boolean" }).notNull().default(true),
  updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const walletSettings = sqliteTable("wallet_settings", {
  id: integer("id").primaryKey(),
  minTopup: integer("min_topup").notNull().default(10000),
  midtransTopupEnabled: integer("midtrans_topup_enabled", { mode: "boolean" }).notNull().default(false),
  midtransCheckoutEnabled: integer("midtrans_checkout_enabled", { mode: "boolean" }).notNull().default(false),
  ipaymuTopupEnabled: integer("ipaymu_topup_enabled", { mode: "boolean" }).notNull().default(false),
  ipaymuCheckoutEnabled: integer("ipaymu_checkout_enabled", { mode: "boolean" }).notNull().default(false),
  updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const walletTopups = sqliteTable(
  "wallet_topups",
  {
    id: text("id").primaryKey(),
    customerId: text("customer_id").notNull().references(() => customerUsers.id, { onDelete: "cascade" }),
    amount: integer("amount").notNull(),
    senderName: text("sender_name").notNull(),
    paymentMethod: text("payment_method").notNull(),
    proofUrl: text("proof_url").notNull(),
    source: text("source", { enum: ["manual", "midtrans", "ipaymu"] }).notNull().default("manual"),
    referenceId: text("reference_id"),
    midtransTransactionId: text("midtrans_transaction_id"),
    midtransPaymentNo: text("midtrans_payment_no"),
    midtransPaymentName: text("midtrans_payment_name"),
    midtransPaymentUrl: text("midtrans_payment_url"),
    midtransExpiredAt: text("midtrans_expired_at"),
    midtransMode: text("midtrans_mode", { enum: ["snap", "bisnap"] }),
    ipaymuTransactionId: text("ipaymu_transaction_id"),
    ipaymuPaymentNo: text("ipaymu_payment_no"),
    ipaymuPaymentName: text("ipaymu_payment_name"),
    ipaymuPaymentUrl: text("ipaymu_payment_url"),
    ipaymuExpiredAt: text("ipaymu_expired_at"),
    paymentFee: integer("payment_fee").notNull().default(0),
    paymentTotal: integer("payment_total").notNull().default(0),
    status: text("status", { enum: ["pending", "approved", "rejected"] }).notNull().default("pending"),
    adminNotes: text("admin_notes"),
    reviewedBy: text("reviewed_by"),
    reviewedAt: text("reviewed_at"),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("wallet_topups_customer_created_idx").on(table.customerId, table.createdAt), index("wallet_topups_status_created_idx").on(table.status, table.createdAt)],
);

export const walletTransactions = sqliteTable(
  "wallet_transactions",
  {
    id: text("id").primaryKey(),
    customerId: text("customer_id").notNull().references(() => customerUsers.id, { onDelete: "cascade" }),
    direction: text("direction", { enum: ["credit", "debit"] }).notNull(),
    amount: integer("amount").notNull(),
    balanceBefore: integer("balance_before").notNull(),
    balanceAfter: integer("balance_after").notNull(),
    reference: text("reference").notNull(),
    description: text("description").notNull(),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("wallet_transactions_reference_unique").on(table.reference), index("wallet_transactions_customer_created_idx").on(table.customerId, table.createdAt)],
);

export const productReviews = sqliteTable(
  "product_reviews",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    customerId: text("customer_id").notNull().references(() => customerUsers.id, { onDelete: "cascade" }),
    productSlug: text("product_slug").notNull(),
    rating: integer("rating").notNull(),
    title: text("title"),
    body: text("body").notNull(),
    isVerifiedPurchase: integer("is_verified_purchase", { mode: "boolean" }).notNull().default(false),
    isVisible: integer("is_visible", { mode: "boolean" }).notNull().default(true),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("product_reviews_customer_product_unique").on(table.customerId, table.productSlug), index("product_reviews_product_visible_idx").on(table.productSlug, table.isVisible, table.createdAt)],
);

export const homeBanners = sqliteTable(
  "home_banners",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    title: text("title").notNull(),
    subtitle: text("subtitle").notNull().default(""),
    imageUrl: text("image_url").notNull(),
    ctaLabel: text("cta_label").notNull().default("Lihat produk"),
    ctaHref: text("cta_href").notNull().default("#produk"),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("home_banners_active_sort_idx").on(table.isActive, table.sortOrder)],
);

export const sitePopups = sqliteTable(
  "site_popups",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    title: text("title").notNull(),
    body: text("body").notNull(),
    primaryLabel: text("primary_label"),
    primaryHref: text("primary_href"),
    secondaryLabel: text("secondary_label"),
    secondaryHref: text("secondary_href"),
    dismissDays: integer("dismiss_days").notNull().default(7),
    isActive: integer("is_active", { mode: "boolean" }).notNull().default(true),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [index("site_popups_active_sort_idx").on(table.isActive, table.sortOrder)],
);

export const newsArticles = sqliteTable(
  "news_articles",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    slug: text("slug").notNull(),
    title: text("title").notNull(),
    summary: text("summary").notNull().default(""),
    body: text("body").notNull(),
    coverUrl: text("cover_url"),
    isPublished: integer("is_published", { mode: "boolean" }).notNull().default(false),
    publishedAt: text("published_at"),
    sortOrder: integer("sort_order").notNull().default(0),
    createdAt: text("created_at").notNull().default(sql`CURRENT_TIMESTAMP`),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [uniqueIndex("news_articles_slug_unique").on(table.slug), index("news_articles_published_sort_idx").on(table.isPublished, table.publishedAt, table.sortOrder)],
);

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
