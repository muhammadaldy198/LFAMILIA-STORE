import { sql } from "drizzle-orm";
import { index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

export const products = sqliteTable(
  "products",
  {
    id: integer("id").primaryKey({ autoIncrement: true }),
    slug: text("slug").notNull(),
    name: text("name").notNull(),
    publisher: text("publisher").notNull().default(""),
    category: text("category", { enum: ["game", "voucher"] }).notNull(),
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
    subtotal: integer("subtotal").notNull(),
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
