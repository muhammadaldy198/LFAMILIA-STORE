import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const catalog = readFileSync(new URL('../../resources/js/Pages/Admin/Catalog.vue', import.meta.url), 'utf8');
const promotions = readFileSync(new URL('../../resources/js/Pages/Admin/Promotions.vue', import.meta.url), 'utf8');
const reports = readFileSync(new URL('../../resources/js/Pages/Admin/Reports.vue', import.meta.url), 'utf8');
const providers = readFileSync(new URL('../../resources/js/Pages/Admin/Providers.vue', import.meta.url), 'utf8');
const support = readFileSync(new URL('../../resources/js/Pages/Admin/Support.vue', import.meta.url), 'utf8');
const health = readFileSync(new URL('../../resources/js/Pages/Admin/Health.vue', import.meta.url), 'utf8');
const audit = readFileSync(new URL('../../resources/js/Pages/Admin/Audit.vue', import.meta.url), 'utf8');
for (const page of [providers, audit]) assert.doesNotMatch((page.split('<template>')[1] || page), /class="lf-admin-summary/);
assert.match((health.split('<template>')[1] || health), /Perlu perhatian/);
const customers = readFileSync(new URL('../../resources/js/Pages/Admin/CustomerDetail.vue', import.meta.url), 'utf8');

assert.match(customers, /Pesanan/);
assert.match(customers, /Saldo/);

assert.match(catalog, /Tampilan Produk/);
assert.match(catalog, /admin\/vouchers\/popular\/["'] \+ item\.id/);
assert.match(catalog, /canManagePopular = computed\(\(\) => page\.props\.adminPanel\?\.menu\?\.some\(item => item\.href === '\/admin\/vouchers'\)/);
assert.match(catalog, /<Button v-if="canManagePopular"[^>]*@click="togglePopular\(item\)"/);
assert.doesNotMatch(promotions, /Populer Sekarang|popularProducts|Produk populer/);
assert.match(reports.split('<template>')[1] || reports, /Produk Terlaris/);
assert.match(reports, /Kategori Teratas/);
assert.match(reports, /Kinerja penyedia layanan/);
