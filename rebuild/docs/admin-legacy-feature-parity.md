# Audit kesetaraan panel admin lama

Tanggal: 2 Oktober 2026 (WIB). Baseline lama: `45eb340740ef0a72c02eed5fd3933a8bb7d5c176`. Panel sebelum audit: `3bef00eb1aa4299151e1243809e9498c3456a7a3`.

**Status: BELUM LENGKAP.** Kelulusan smoke test 18 menu bukan bukti semua fitur lama dipulihkan. Dokumen ini mempertahankan kekurangan secara eksplisit. Label tersedia berarti ada jalur implementasi, bukan seluruh kemungkinan interaksi telah dites.

## Perbandingan tiap endpoint lama

| Area API lama | Status dan rincian |
|---|---|
| `auth/setup` | Diganti: aktivasi satu kali dan password mandiri; login teruji. Bukan pendaftaran admin publik. |
| `balances` | Sebagian: penyesuaian saldo pelanggan dan ledger tersedia; saldo operasional terpisah per admin belum tersedia. |
| `categories` | Setara+: tambah/ubah/hapus/status/urutan/gambar tersedia, slug dapat diedit, dan ikon preset lama dipakai sebagai cadangan jika gambar kategori kosong. |
| `content` | Setara+: banner desktop/mobile, urutan/status/tautan klik, satu pop-up tanpa tombol tambahan, berita, logo/gambar storefront, footer banner, teks bantuan/footer, dan upload media tersedia serta terhubung ke frontend. |
| `customer-cleanup` | Dipulihkan di batch ini: toggle otomatis, periode 7–365 hari, jalankan sekarang, metadata terakhir; penghapusan akun kosong melindungi riwayat. |
| `dashboard-integrations` | Sebagian: health/integrasi tersedia; perlu perbandingan widget dashboard lama. |
| `digiflazz-monitor` | Setara+: status koneksi, saldo read-only Pemilik, ringkasan normal/peringatan/kritis, seller, modal/baseline, stok, cut-off, multi, deskripsi, filter kategori/produk/brand/kesehatan, pagination, dan transaksi terbaru tersedia. Ambang stok/harga dapat diubah dari panel. |
| `digiflazz-pricing` | Setara tanpa duplikasi: sinkron otomatis/manual/per SKU/per produk tersedia; interval sinkron dapat diubah. Margin rupiah/persen/harga final serta max price nominal tetap dikelola di Produk sebagai satu sumber konfigurasi. |
| `faqs` | Setara: tambah/edit/hapus, status aktif, jawaban, dan urutan tersedia serta dipakai halaman Pertanyaan Umum. |
| `integrations` | Credential terenkripsi/reveal/tes tersedia; perlu perbandingan semua profil field/env/mode lama. |
| `media` | Upload gambar tersedia via media Spatie; detail optimasi gambar lama perlu dibandingkan. |
| `members` | Sebagian: tier AUTO/manual dan saldo tersedia; penghapusan akun kosong dipulihkan; tier bonus/progress/edit profil belum lengkap. |
| `nickname-tools` | Cek game/region/PLN tersedia; belum dites dengan credential live. |
| `orders` | Setara+: daftar/detail/filter/pagination, riwayat pembayaran dan penanganan, pesanan manual, ekspor pilihan, cek ulang pembayaran/proses, retry aman, serta kirim ulang hasil tersimpan tersedia. |
| `payment-methods` | Sebagian: channel/fee/status/routing tersedia; tambah/hapus channel, logo/deskripsi/kelompok dan sinkron daftar lama belum lengkap. |
| `payment-page` | Editor tampilan pembayaran tersedia; perlu perbandingan setiap field pengaturan lama. |
| `payment-routing` | Sebagian: routing prioritas tersedia; pilihan gateway top-up tunggal dan profil mode/env perlu dibandingkan. |
| `product-content` | Setara: deskripsi, pemberitahuan, media produk/banner/nominal, serta jam dan instruksi manual dikelola di editor Produk tanpa halaman duplikat. |
| `product-input` | Setara+: editor kolom pelanggan generik menggantikan shortcut ID/ID+Server, dengan urutan, wajib/opsional, cek nickname, dan format tujuan yang tetap dapat diedit. |
| `product-package-provider` | Setara+: sumber Digiflazz mendukung impor/sinkron/prioritas/max-price dan stok kode digital mendukung kunci stok, modal, prioritas, impor terenkripsi, ketersediaan, retry, serta delivery_payload sukses. |
| `product-package-status` | Aktif/nonaktif nominal tersedia. |
| `products` | Setara+: CRUD/status, slug, kategori, media, nominal, pricing, reorder/drag, hapus aman, duplikasi manual, tab nominal, initials/accent/instant, input pelanggan, nickname, jam manual, dan aktivasi aman tersedia. Pengaturan Populer tetap di menu Promo agar tidak duplikat. |
| `promotions` | Sebagian: popular dan voucher discount tersedia; fungsi promosi lain perlu dipetakan. |
| `reviews` | Setara: Admin hanya mengatur tampil/sembunyi ulasan terverifikasi; isi, rating, dan identitas ulasan tidak diedit. |
| `session` | Guard admin dan role terpisah tersedia. |
| `storefront` | Setara tanpa duplikasi: logo/footer/banner dan teks presentasi berada di Banner & Konten; nama toko, kontak, akun sosial, tautan bantuan, dan jam layanan tetap satu sumber di Pengaturan. |
| `summary` | Sebagian: dashboard/laporan/health tersedia; perlu perbandingan metrik dan filter. |
| `support` | Balasan/status/quick replies tersedia; perlu perbandingan detail modal/riwayat tiket. |
| `team` | SUPER_ADMIN/ADMIN tersedia; STAFF dihapus sesuai instruksi terbaru, bukan regresi. Perlu perbandingan field hak akses. |
| `vouchers` | Setara tanpa duplikasi: voucher diskon tetap di Promo, sedangkan stok kode produk lama dipindahkan ke nominal Produk sebagai Stok Kode Digital; kode terenkripsi dan hanya masuk delivery_payload setelah order berhasil. |
| `wallet/proof` | Bukti top-up privat belum memiliki tampilan admin setara. |
| `wallet` | Sebagian: minimum top-up dan saldo pelanggan tersedia; daftar top-up/pembayaran/ledger admin belum setara tab lama. |

## Inventaris sumber API: kontrak dan aksi

Field berikut diekstrak dari kode lama, untuk menghindari kehilangan detail ketika controller baru mengubah nama atau bentuk data. Daftar bersifat inventaris, tidak otomatis membuktikan field telah dipulihkan.

### `app/api/admin/auth/setup/route.ts`

Metode: GET, POST.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: username, name, password

### `app/api/admin/balances/route.ts`

Metode: GET, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: accountType, targetId, operation, amount, reason

### `app/api/admin/categories/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: id, slug, name, icon, isActive, sortOrder

### `app/api/admin/content/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/customer-cleanup/route.ts`

Metode: GET, PUT, POST.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: enabled, inactivityDays

### `app/api/admin/dashboard-integrations/route.ts`

Metode: GET.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/digiflazz-monitor/route.ts`

Metode: GET, POST.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/digiflazz-pricing/route.ts`

Metode: GET, POST, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: isAutoSync, syncNow, productId, packageSku, packageId, maxPrice, marginType, marginValue

### `app/api/admin/faqs/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: id, question, answer, isActive, sortOrder

### `app/api/admin/integrations/route.ts`

Metode: GET, PUT.

Aksi eksplisit: save_profile, save_selections, test_digiflazz

Field validasi: action, provider, mode, environment, values, clearFields, selections, digiflazzEnvironment

### `app/api/admin/media/route.ts`

Metode: POST.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/members/route.ts`

Metode: GET, PUT, PATCH, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: tier, discountPercent, benefits, settings, customerId, role, addBalance, reason

### `app/api/admin/nickname-tools/route.ts`

Metode: POST.

Aksi eksplisit: game, region, pln

Field validasi: action, gameCode, userId, server, customerNumber

### `app/api/admin/orders/route.ts`

Metode: GET, POST, PATCH.

Aksi eksplisit: admin_manual, complete_manual, refresh_fulfillment

Field validasi: customer, phone, customerEmail, product, packageName, destination, total, payment, id, action, serialNumber

### `app/api/admin/payment-methods/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: gateway_status, sync

Field validasi: id, method, channel, name, description, imageUrl, isActive, sortOrder, gateway, action, enabled, gateways

### `app/api/admin/payment-page/route.ts`

Metode: GET, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: accentColor, eyebrow, pendingTitle, paidTitle, failedTitle, subtitle, invoiceNoticeTitle, invoiceNoticeText, pendingStatusText, paidStatusText, failedStatusText, payButtonText, checkStatusButtonText, checkInvoiceButtonText, supportText, showStoreBrand, showInvoiceNotice, showOrderSummary, showStatusBox, showSupport

### `app/api/admin/payment-routing/route.ts`

Metode: GET, PUT.

Aksi eksplisit: save_modes, save_wallet_topup_gateway, save_profile

Field validasi: action, dokuEnvironment, midtransEnvironment, walletTopupGateway, provider, mode, environment, values

### `app/api/admin/product-content/route.ts`

Metode: GET, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: title, body, isActive, sortOrder, dbId, imageUrl, bannerUrl, manualInstructions, manualOpenTime, manualCloseTime, manualTimezone, notices

### `app/api/admin/product-input/route.ts`

Metode: GET, PATCH.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: checkoutType, labelId, labelServer, nicknameGameCode

### `app/api/admin/product-package-provider/route.ts`

Metode: PATCH.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: packageId, providerCode, providerSku, pricingMode, marginType, marginValue, providerMaxPrice

### `app/api/admin/product-package-status/route.ts`

Metode: PATCH.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: packageId, isActive

### `app/api/admin/products/route.ts`

Metode: GET, POST, PATCH, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: id, label, price, note, group, imageUrl, providerCode, providerSku, supplierPrice, providerMaxPrice, pricingMode, marginType, marginValue, isActive, sortOrder, placeholder, required, title, body, dbId, slug, name, publisher, category, bannerUrl, initials, accent, inputLabel, inputPlaceholder, inputFields, needsServer, popular, instant, fulfillmentType, targetTemplate, manualInstructions, manualOpenTime, manualCloseTime, manualTimezone, packageTabsEnabled, packageTabs, packages, notices

### `app/api/admin/promotions/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: voucher, flash

Field validasi: kind, description, minPurchase, salePrice

### `app/api/admin/reviews/route.ts`

Metode: GET, PATCH.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/session/route.ts`

Metode: GET.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/storefront/route.ts`

Metode: GET, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: storeName, storeShortName, tagline, announcement, bannerEnabled, bannerEyebrow, bannerTitle, bannerHighlight, bannerDescription, bannerCtaLabel, bannerCtaHref, supportWhatsapp, supportEmail, supportHours, supportWidgetEnabled

### `app/api/admin/summary/route.ts`

Metode: GET.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/support/route.ts`

Metode: GET, PATCH.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/team/route.ts`

Metode: GET, POST, DELETE.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: id, username, name, role, isActive, password

### `app/api/admin/vouchers/route.ts`

Metode: GET, POST, PATCH.

Aksi eksplisit: import, reveal, retry

Field validasi: action, stockKey, codes, orderId

### `app/api/admin/wallet/proof/route.ts`

Metode: GET.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: Periksa fungsi langsung di baseline.

### `app/api/admin/wallet/route.ts`

Metode: GET, PUT.

Aksi eksplisit: Tidak memakai discriminator aksi.

Field validasi: minTopup, automaticTopupEnabled

## Inventaris komponen dan interaksi lama

- `components/admin-account-menu.tsx`: AdminAccountMenu, closeOutside, closeWithEscape, navigate.

- `components/admin-balance-manager.tsx`: requestJson, rupiah, AdminBalanceManager, switchType, adjustBalance.

- `components/admin-brand-logo.tsx`: AdminBrandLogo, handleError.

- `components/admin-customer-directory.tsx`: AdminCustomerDirectory.

- `components/admin-customer-workspace.tsx`: rupiah, requestJson, AdminCustomerWorkspace, saveTierSettings, saveMember, updateSetting, saveCleanupSettings, runCleanupNow, deleteMember.

- `components/admin-dashboard.tsx`: AdminDashboard, handleShortcut, submitGlobalSearch, Brand, SidebarHelp.

- `components/admin-digiflazz-workspace.tsx`: readJson, formatRupiah, formatDate, AdminDigiflazzWorkspace, load, syncNow, toggleAutoSync, chooseCategory, Stat, Status.

- `components/admin-experience-manager.tsx`: prepareContentImage, mapBanner, mapPopup, mapNews, mapFaq, mapReview, panelJson, saveContentItem, saveReview, togglePayload, editPayload, AdminExperienceManager, loadContent, focus, add, toggle, saveEditor, deleteEditor, updateImage, BannerTable, MiniPanel, ContentPanel, EditorPanel, uploadImage, BannerImageUpload, EditorField, EditorTextArea, BannerArtwork, Switch, flip, labelKind.

- `components/admin-homepage-category-manager.tsx`: AdminHomepageCategoryManager, update, move, add, saveAll, remove.

- `components/admin-integration-workspace.tsx`: AdminIntegrationWorkspace, put, paymentPut, save, test, changeDokuEnvironment, changeMidtransEnvironment, Card, Text, Select.

- `components/admin-kokinpay-workspace.tsx`: AdminKokinpayWorkspace, runCheck, ResultBox.

- `components/admin-media-upload.tsx`: AdminMediaUpload, upload, optimizeImage.

- `components/admin-notifications.tsx`: AdminNotifications, closeDropdown, closeWithEscape, saveReadIds, markAsRead, markAllAsRead, openItem, buildNotifications, timestampValue, labelStatus, formatTimestamp, iconFor, toneFor.

- `components/admin-operations-workspaces.tsx`: api, saveDownload, AdminPromoWorkspace, load, persist, remove, togglePopular, PromoModal, submit, AdminSupportWorkspace, load, update, TicketModal, AdminReportsWorkspace, load, exportReport, AdminTeamWorkspace, load, save, remove, TeamModal, submit, AdminSettingsWorkspace, load, save, upload, exportConfig, Table, InfoBox, ErrorBox, SettingToggle, StoreField, ImageSetting.

- `components/admin-order-manager.tsx`: mapOrderStatus, mapApiOrder, orderActivity, friendlyPayment, shortDate, exportRows, AdminOrderManager, loadOrders, resetFilters, refresh, exportCsv, toggleAll, applyBulkAction, fetchOrderDetail, openOrderDetail, refreshPaymentStatus, refreshFulfillmentStatus, retryDigiflazz, addManualOrder, ToolbarButton, MetricCard, FilterSelect, DesktopOrderTable, toggle, Checkbox, ProductThumb, PaymentIcon, StatusBadge, Pagination, ActivityPanel, ActivityItem, eventLabel, eventSource, OrderDetailModal, copyInvoice, runRefresh, completeManual, DetailSection, DetailLine, ManualOrderModal, FormField, formatRupiah, csvCell.

- `components/admin-overview.tsx`: AdminOverview, MetricCard, Panel, PanelHeader, SalesBars, IntegrationRow, StatusLine, EmptyState, StatusBadge, money, relativeTime, formatDate, formatTime.

- `components/admin-payment-workspace.tsx`: groupForMethod, AdminPaymentWorkspace, readImage, request, saveChannel, openCreateChannel, saveEditedChannel, deleteChannel, save, syncChannels, testConnection, GatewayControl, SwitchRow, ImageEditor, feeLabel, formatMoney, formatDate, Detail.

- `components/admin-product-manager.tsx`: displayCategory, mapProductRecord, readJson, AdminProductManager, resetFilters, addProduct, toggleProduct, ProductTable, ProductEditor, saveInputSettings, buildPayload, uploadProductImage, saveProductChanges, refreshSellerMonitor, moveNominal, moveSection, dropNominal, dropSection, addManualNominal, copyNominal, updateNominal, applyGlobalMargin, syncNominal, addSection, NominalTable, SectionTable, NominalEditorModal, submit, GlobalMarginModal, ProductSettingsPanel, ProductMediaField, ControlledField, SettingSwitch, EditorTabPanel, digiflazzNominalLabel, ImportNominalModal, chooseCategory, finish, ManualProductModal, SimpleModal, ModalActions, Field, ActionButton, IconButton, CompactSelect, PageButton, Box, Switch, ProductImage, CategoryBadge, NominalArtwork, moveItem, moveBefore, slugify, formatRupiah.

- `components/admin-workspace-ui.tsx`: WorkspaceHeader, Panel, MetricCard, TabBar, Toggle, Status, Field, CopyUrl, copy, Modal.

## Temuan perilaku nyata versus tombol dekoratif

- `applyBulkAction` lama hanya mengekspor CSV pilihan, tidak mengganti status massal atau melakukan refund. Restorasi harus menyediakan ekspor pilihan tanpa mengarang operasi uang yang tidak ada.
- Seller monitor lama tidak memberikan rating/SLA seller. Ia menghitung sehat/peringatan/kritis dari status buyer/seller, stok, cutoff, perubahan harga dan pergantian seller. Jangan mengklaim rating atau auto-select seller sebagai fitur lama tanpa bukti.
- Endpoint `vouchers` lama mengelola stok voucher digital, sedangkan promo/voucher diskon adalah fungsi berbeda. Kesamaan nama menu tidak memenuhi kesetaraan ini.
- Cleanup Laravel sebelum audit sudah terjadwal 30 hari namun kontrol di panel hilang. Batch ini mengembalikan kontrol pemilik dan periode; tidak menjalankan penghapusan pada database produksi selama audit.
- `AdminCatalogController::index` sebelumnya mengurutkan nominal_value sebelum sort_order sehingga urutan custom hilang setelah reload. Batch ini membalik prioritas pengurutan dan menambahkan regresi dengan nilai nominal berbeda.

## Batas verifikasi

Pengujian menggunakan MySQL terpisah dan API provider mock. Credential provider produksi belum dikonfigurasi sehingga transaksi eksternal tidak dapat dinyatakan teruji. Login/aktivasi dan browser mobile batch sebelumnya sudah dites, tetapi audit ini membutuhkan lebih dari sekadar halaman HTTP 200.
