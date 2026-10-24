<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { label, rupiah, tone, wibDateTime, copyText } from '../../lib/adminFormat';

const props = defineProps({
    order: { type: Object, required: true },
    attempts: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    canViewCustomer: Boolean,
    canResendDelivery: Boolean,
    canCancel: Boolean,
    canCheckFulfillment: Boolean,
});

const busy = ref('');
const post = (url, body = {}, labelText = '') => {
    busy.value = labelText || url;
    router.post(url, body, { preserveScroll: true, onFinish: () => { busy.value = ''; } });
};

const copied = ref('');
const copy = async (text) => {
    if (!await copyText(text)) return;
    copied.value = text;
    setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
};

const latest = computed(() => props.attempts[0] || null);
const delivery = computed(() => props.order.delivery || {});
const deliveryCode = computed(() => delivery.value.code || delivery.value.serial_number || '');
const deliveryNote = computed(() => delivery.value.note || '');

const modal = ref('');
const form = reactive({ delivery_code: '', note: '', reason: '' });
const openModal = (kind) => {
    modal.value = kind;
    form.delivery_code = '';
    form.note = '';
    form.reason = '';
};

const finish = () => {
    const attempt = latest.value;
    if (!attempt) return;
    post(`/admin/fulfillment/${attempt.id}/complete`, { delivery_code: form.delivery_code, note: form.note }, 'complete');
    modal.value = '';
};
const fail = () => {
    const attempt = latest.value;
    if (!attempt) return;
    post(`/admin/fulfillment/${attempt.id}/fail`, { reason: form.reason }, 'fail');
    modal.value = '';
};

const resendOpen = ref(false);
const resend = () => {
    post(`/admin/orders/${props.order.id}/resend-delivery`, {}, 'resend');
    resendOpen.value = false;
};
const cancelOpen = ref(false);
const cancel = () => {
    post(`/admin/orders/${props.order.id}/cancel`, { confirmed: true }, 'cancel');
    cancelOpen.value = false;
};

const whatsapp = computed(() => {
    const phone = String(props.order.buyer_phone || '').replace(/[^0-9]/g, '');
    if (!phone) return null;
    const normalized = phone.startsWith('0') ? '62' + phone.slice(1) : phone;
    return `https://wa.me/${normalized}?text=${encodeURIComponent(`Halo, terkait pesanan ${props.order.order_number}`)}`;
});
</script>

<template>
    <Head :title="order.order_number" />
    <AdminLayout>
        <Link href="/admin/orders" class="adm-btn sm" style="margin-bottom:10px">← Semua pesanan</Link>

        <header class="adm-head">
            <div>
                <h1>{{ order.order_number }}</h1>
                <p>
                    <span class="adm-tag" :class="tone(order.status)">{{ order.status_label || label(order.status) }}</span>
                    Dibuat {{ wibDateTime(order.created_at) }}<template v-if="order.paid_at"> · Pembayaran diterima {{ wibDateTime(order.paid_at) }}</template>
                </p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="copy(order.order_number)">{{ copied === order.order_number ? 'Tersalin' : 'Salin nomor' }}</button>
                <a v-if="whatsapp" class="adm-btn" :href="whatsapp" target="_blank" rel="noopener">Hubungi pelanggan</a>
                <button v-else type="button" class="adm-btn" disabled>Hubungi pelanggan</button>
                <button type="button" class="adm-btn" @click="router.reload({ only: ['order', 'attempts', 'payments', 'events'] })">Muat ulang</button>
                <button v-if="canCancel" type="button" class="adm-btn danger" @click="cancelOpen = true">Batalkan pesanan</button>
            </div>
        </header>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Status saat ini</h2>
                    <p>{{ order.status_label || label(order.status) }}<template v-if="latest"> · penanganan ke-{{ latest.attempt_no }} · {{ latest.status_label }}</template></p>
                </div>
                <div class="adm-head-actions">
                    <button v-if="latest?.can_manual" type="button" class="adm-btn primary" @click="openModal('complete')">Selesaikan pesanan</button>
                    <button v-if="latest?.can_manual" type="button" class="adm-btn danger" @click="openModal('fail')">Nyatakan gagal</button>
                    <button v-if="latest?.can_retry" type="button" class="adm-btn" :disabled="busy === 'retry'" @click="post(`/admin/fulfillment/${latest.id}/retry`, {}, 'retry')">Lanjutkan pengiriman</button>
                </div>
            </div>
        </section>

        <div class="adm-split">
            <section class="adm-card">
                <div class="adm-card-head"><h2>Ringkasan pesanan</h2></div>
                <div class="adm-card-body">
                    <dl class="adm-kv">
                        <dt>Produk</dt><dd>{{ order.product_name }}</dd>
                        <dt>Paket</dt><dd>{{ order.package_name }}</dd>
                        <dt>Total pembayaran</dt><dd>{{ rupiah(order.total_idr) }}</dd>
                        <dt>Metode pembayaran</dt><dd>{{ order.payment_method }}</dd>
                        <dt>Penyedia</dt><dd>{{ order.provider }}</dd>
                        <dt>Dibuat</dt><dd>{{ wibDateTime(order.created_at) }}</dd>
                        <dt>Pembayaran diterima</dt><dd>{{ order.paid_at ? wibDateTime(order.paid_at) : 'Belum diterima' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head"><h2>Pelanggan dan tujuan</h2></div>
                <div class="adm-card-body">
                    <dl class="adm-kv">
                        <dt>Nama pelanggan</dt><dd>{{ order.buyer_name || 'Pelanggan tamu' }}</dd>
                        <dt>Nomor telepon/WhatsApp</dt><dd>{{ canViewCustomer ? (order.buyer_phone || '—') : 'Tidak berizin' }}</dd>
                        <dt>Email</dt><dd>{{ canViewCustomer ? (order.buyer_email || '—') : 'Tidak berizin' }}</dd>
                        <template v-for="item in order.destinations" :key="item.label">
                            <dt>{{ item.label }}</dt><dd>{{ item.value }}</dd>
                        </template>
                        <dt>Nama akun</dt><dd>{{ order.nickname || '—' }}</dd>
                    </dl>
                </div>
            </section>
        </div>

        <section class="adm-card">
            <div class="adm-card-head">
                <h2>Hasil pengiriman</h2>
                <button v-if="canResendDelivery" type="button" class="adm-btn sm" :disabled="busy === 'resend'" @click="resendOpen = true">Kirim ulang hasil</button>
            </div>
            <div class="adm-card-body">
                <template v-if="deliveryCode || deliveryNote">
                    <p v-if="deliveryCode" class="mono" style="word-break:break-all">{{ deliveryCode }}</p>
                    <p v-if="deliveryNote" class="adm-note">{{ deliveryNote }}</p>
                </template>
                <p v-else class="adm-note">Belum ada hasil pengiriman untuk pesanan ini.</p>
            </div>
        </section>

        <div class="adm-split">
            <section class="adm-card">
                <div class="adm-card-head"><h2>Pembayaran</h2></div>
                <div class="adm-card-body tight">
                    <div v-if="payments.length" class="adm-scroll">
                        <table class="adm-table">
                            <thead><tr><th>Metode</th><th>Status</th><th class="num">Nominal</th><th>Dibuat</th><th>Tindakan</th></tr></thead>
                            <tbody>
                                <tr v-for="payment in payments" :key="payment.id">
                                    <td>{{ payment.method }}<p class="dim">{{ payment.channel_code }}</p></td>
                                    <td><span class="adm-tag" :class="tone(payment.status)">{{ payment.status_label }}</span></td>
                                    <td class="num">{{ rupiah(payment.amount_idr) }}</td>
                                    <td>{{ wibDateTime(payment.created_at) }}</td>
                                    <td>
                                        <button v-if="payment.can_check" type="button" class="adm-btn sm" :disabled="busy === 'check-payment'" @click="post(`/admin/orders/${order.id}/check-payment`, {}, 'check-payment')">Periksa pembayaran</button>
                                        <button v-else-if="payment.can_confirm" type="button" class="adm-btn sm" :disabled="busy === 'check-payment'" @click="post(`/admin/orders/${order.id}/check-payment`, {}, 'check-payment')">Konfirmasi pembayaran</button>
                                        <span v-else class="adm-note">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="adm-empty">Belum ada transaksi pembayaran.</p>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head"><h2>Riwayat pesanan</h2></div>
                <div class="adm-card-body">
                    <div v-if="events.length" class="adm-list">
                        <div v-for="event in events" :key="event.id" class="adm-list-row">
                            <strong>{{ event.label }}</strong>
                            <span>{{ event.from }} → {{ event.to }}</span>
                            <span>{{ wibDateTime(event.created_at) }}</span>
                        </div>
                    </div>
                    <p v-else class="adm-empty">Belum ada riwayat.</p>
                </div>
            </section>
        </div>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Proses pesanan</h2>
                    <p v-if="latest">Penanganan ke-{{ latest.attempt_no }} · {{ latest.source }}</p>
                </div>
                <div class="adm-head-actions">
                    <button v-if="canCheckFulfillment && latest" type="button" class="adm-btn sm" :disabled="busy === 'reconcile'" @click="post(`/admin/fulfillment/${latest.id}/reconcile`, {}, 'reconcile')">Periksa status proses</button>
                </div>
            </div>
            <div class="adm-card-body tight">
                <div v-if="attempts.length" class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Ke</th><th>Sumber</th><th>Status</th><th>Terakhir diperiksa</th><th>Catatan</th><th>Tindakan</th></tr></thead>
                        <tbody>
                            <tr v-for="(attempt, index) in attempts" :key="attempt.id">
                                <td class="mono">{{ attempt.attempt_no }}</td>
                                <td>{{ attempt.provider }}<p class="dim">{{ attempt.process_reference || 'Tanpa referensi' }}</p></td>
                                <td><span class="adm-tag" :class="tone(attempt.status)">{{ attempt.status_label }}</span></td>
                                <td>{{ attempt.checked_at ? wibDateTime(attempt.checked_at) : '—' }}</td>
                                <td>{{ attempt.note || '—' }}</td>
                                <td>
                                    <template v-if="index === 0">
                                        <button v-if="attempt.can_manual" type="button" class="adm-btn sm" @click="openModal('complete')">Selesaikan</button>
                                        <button v-if="attempt.can_manual" type="button" class="adm-btn sm danger" @click="openModal('fail')">Gagal</button>
                                        <button v-if="attempt.can_retry" type="button" class="adm-btn sm" :disabled="busy === 'retry'" @click="post(`/admin/fulfillment/${attempt.id}/retry`, {}, 'retry')">Lanjutkan</button>
                                        <span v-if="!attempt.can_manual && !attempt.can_retry" class="adm-note">—</span>
                                    </template>
                                    <span v-else class="adm-note">Riwayat</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="adm-empty">Belum ada proses pengiriman.</p>
            </div>
        </section>

        <AdminModal v-model:open="modal" :title="modal === 'fail' ? 'Nyatakan pesanan gagal' : 'Selesaikan pesanan'" :description="modal === 'fail' ? 'Alasan dicatat pada riwayat pesanan.' : 'Isi hasil yang dikirim ke pelanggan.'">
            <div v-if="modal === 'complete'" class="adm-grid">
                <label class="adm-field"><span>Hasil / kode pengiriman</span><textarea v-model="form.delivery_code" class="adm-textarea" maxlength="2000" /></label>
                <label class="adm-field"><span>Catatan</span><textarea v-model="form.note" class="adm-textarea" maxlength="4000" /></label>
            </div>
            <div v-else class="adm-grid">
                <label class="adm-field"><span>Alasan <b class="req">*</b></span><textarea v-model="form.reason" class="adm-textarea" maxlength="4000" required /></label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="modal = ''">Batal</button>
                <button v-if="modal === 'complete'" type="button" class="adm-btn primary" :disabled="busy === 'complete'" @click="finish">Selesaikan pesanan</button>
                <button v-else type="button" class="adm-btn danger" :disabled="busy === 'fail' || !form.reason.trim()" @click="fail">Nyatakan gagal</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="resendOpen" title="Kirim ulang hasil pesanan" description="Hasil dikirim ke email pelanggan.">
            <p class="adm-note">Hasil pengiriman akan dikirim ulang ke {{ order.buyer_email }}.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="resendOpen = false">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="busy === 'resend'" @click="resend">Kirim ulang</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="cancelOpen" title="Batalkan pesanan" description="Pembatalan hanya untuk pesanan yang seluruh prosesnya gagal.">
            <p class="adm-note">Pesanan {{ order.order_number }} akan dibatalkan dan tidak diproses lagi.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="cancelOpen = false">Batal</button>
                <button type="button" class="adm-btn danger" :disabled="busy === 'cancel'" @click="cancel">Batalkan pesanan</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
