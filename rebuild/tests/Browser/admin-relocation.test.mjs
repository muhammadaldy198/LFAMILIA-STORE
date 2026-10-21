import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const catalog = readFileSync(new URL('../../resources/js/Pages/Admin/Catalog.vue', import.meta.url), 'utf8');
const promotions = readFileSync(new URL('../../resources/js/Pages/Admin/Promotions.vue', import.meta.url), 'utf8');
const reports = readFileSync(new URL('../../resources/js/Pages/Admin/Reports.vue', import.meta.url), 'utf8');
const providers = readFileSync(new URL('../../resources/js/Pages/Admin/Providers.vue', import.meta.url), 'utf8');
const support = readFileSync(new URL('../../resources/js/Pages/Admin/Support.vue', import.meta.url), 'utf8');
const health = readFileSync(new URL('../../resources/js/Pages/Admin/Health.vue', import.meta.url), 'utf8');
const audit = readFileSync(new URL('../../resources/js/Pages/Admin/Audit.vue', import.meta.url), 'utf8');
for (const page of [providers]) assert.doesNotMatch((page.split('<template>')[1] || page), /class="lf-admin-summary/);
// Audit memakai strip ringkasan bersama seperti 13 halaman admin lain (rancangan §2).
assert.match((audit.split('<template>')[1] || audit), /class="lf-admin-summary/);
const categories = readFileSync(new URL('../../resources/js/Pages/Admin/Categories.vue', import.meta.url), 'utf8');
assert.match(categories, /<Sheet :open="creating \|\| Boolean\(selected\)"/);
assert.match((health.split('<template>')[1] || health), /Perlu perhatian/);
const customers = readFileSync(new URL('../../resources/js/Pages/Admin/CustomerDetail.vue', import.meta.url), 'utf8');

assert.match(customers, /Pesanan/);
assert.match(customers, /Saldo/);

assert.match(catalog, /Tampilan Produk/);
assert.match(catalog, /admin\/vouchers\/popular\/["'] \+ item\.id/);
assert.doesNotMatch(catalog, /canManagePopular/);
assert.match(catalog, /<Button type="button" :variant="item\.popular \? 'outline' : 'default'" @click="togglePopular\(item\)"/);
// Promo tidak lagi mengelola produk populer; kalimat penjelas "Produk populer
// diatur di menu Produk" boleh ada, jadi periksa penanda bagiannya saja.
assert.doesNotMatch(promotions, /Populer Sekarang|popularProducts|popularForm|togglePopular/);
// Editor voucher masih memakai daftar kategori/produk untuk cakupan, jadi
// keduanya wajib tetap dideklarasikan sebagai props.
assert.match(promotions, /categories: \{ type: Array, default: \(\) => \[\] \}/);
assert.match(promotions, /scopeProducts: \{ type: Array, default: \(\) => \[\] \}/);
assert.match(promotions, /v-for="category in categories"/);
assert.match(promotions, /v-for="product in scopeProducts"/);
assert.match(reports.split('<template>')[1] || reports, /Produk terlaris/);
assert.match(reports, /Kategori teratas/);
assert.match(reports, /Kinerja penyedia layanan/);
