<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, wibDateTime, tone, label } from '../../lib/adminFormat';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    tickets: { type: Object, default: () => ({ data: [], links: [] }) },
    selectedTicket: { type: Object, default: null },
    quickReplies: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const filter = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    kind: props.filters?.kind || '',
    source: props.filters?.source || '',
    per_page: Number(props.filters?.per_page || 25),
});

const query = extra => Object.fromEntries(Object.entries({
    ...filter, ...extra,
}).filter(([, value]) => value !== '' && value !== null && value !== undefined));

const apply = () => router.get('/admin/support', query({}), { preserveState: true, preserveScroll: true });
const reset = () => {
    Object.assign(filter, { q: '', status: '', kind: '', source: '', per_page: 25 });
    router.get('/admin/support', {}, { preserveState: true, preserveScroll: true });
};
const openTicket = ticket => router.get('/admin/support', query({ ticket: ticket.id }), { preserveState: true, preserveScroll: true });
const closeTicket = () => router.get('/admin/support', query({}), { preserveState: true, preserveScroll: true });

const kindLabel = code => props.kinds.find(kind => kind.code === code)?.label || 'Umum';
const sourceLabel = code => code === 'GUEST' ? 'Tanpa akun' : 'Akun pelanggan';

// ---------- balasan cepat ----------
const repliesOpen = ref(false);
const replies = ref([...props.quickReplies]);
watch(() => props.quickReplies, value => { replies.value = [...value]; }, { deep: true });
const saveReplies = () => {
    router.put('/admin/support/quick-replies', { replies: replies.value }, { preserveScroll: true });
};

// ---------- tanggapi tiket ----------
const form = reactive({ status: '', kind: '', reply: '' });
watch(() => props.selectedTicket, ticket => {
    form.status = ticket?.status || 'OPEN';
    form.kind = ticket?.kind || 'GENERAL';
    form.reply = '';
}, { immediate: true });
const sending = ref(false);
const send = () => {
    if (!props.selectedTicket) return;
    sending.value = true;
    router.put('/admin/support/' + props.selectedTicket.id, {
        status: form.status, kind: form.kind, reply: form.reply,
    }, {
        preserveScroll: true,
        onSuccess: () => { form.reply = ''; },
        onFinish: () => { sending.value = false; },
    });
};
const useReply = text => {
    form.reply = form.reply ? form.reply + '\n' + text : text;
};

const rows = computed(() => (Array.isArray(props.tickets?.data) ? props.tickets.data : []));
</script>

<template>
    <Head title="Layanan Pelanggan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Layanan Pelanggan</h1>
                <p>Tiket dari akun pelanggan maupun tamu tanpa akun, dengan tautan ke pesanan terkait.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
                <button type="button" class="adm-btn primary" @click="repliesOpen = !repliesOpen">
                    {{ repliesOpen ? 'Tutup balasan cepat' : 'Atur balasan cepat' }}
                </button>
            </div>
        </header>

        <p class="adm-summary">
            {{ num(summary.open) }} menunggu · {{ num(summary.in_progress) }} diproses ·
            {{ num(summary.resolved) }} selesai · {{ num(summary.closed) }} ditutup ·
            {{ num(summary.total) }} tiket · {{ num(summary.account) }} dari akun ·
            {{ num(summary.guest) }} tanpa akun
        </p>

        <section v-if="repliesOpen" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Balasan cepat</h2>
                </div>
                <button type="button" class="adm-btn sm" @click="replies.push('')">Tambah balasan</button>
            </div>
            <div class="adm-card-body">
                <div v-for="(reply, index) in replies" :key="index" style="display:flex;gap:6px;align-items:flex-start;margin-bottom:8px">
                    <textarea v-model="replies[index]" class="adm-textarea" rows="2" maxlength="1000" placeholder="Tulis template balasan" />
                    <button type="button" class="adm-btn sm danger" @click="replies.splice(index, 1)">Hapus</button>
                </div>
                <p v-if="!replies.length" class="adm-note">Belum ada balasan cepat. Tambahkan satu untuk mempermudah jawaban.</p>
                <div style="display:flex;justify-content:flex-end;margin-top:8px">
                    <button type="button" class="adm-btn primary" @click="saveReplies">Simpan balasan cepat</button>
                </div>
            </div>
        </section>

        <!-- panel detail tiket -->
        <section v-if="selectedTicket" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>#{{ selectedTicket.id }} · {{ selectedTicket.subject }}</h2>
                    <p>
                        {{ sourceLabel(selectedTicket.source) }} · {{ kindLabel(selectedTicket.kind) }} ·
                        dibuka {{ wibDateTime(selectedTicket.created_at) }}
                    </p>
                </div>
                <div style="display:flex;gap:6px;align-items:center">
                    <span class="adm-tag" :class="tone(selectedTicket.status)">{{ label(selectedTicket.status) }}</span>
                    <button type="button" class="adm-btn sm" @click="closeTicket">Tutup panel</button>
                </div>
            </div>
            <div class="adm-card-body">
                <div class="adm-split">
                    <div>
                        <div class="adm-block">
                            <strong>{{ selectedTicket.customer_name }}</strong>
                            <div class="adm-note">
                                {{ selectedTicket.customer_email || 'Tanpa email' }}
                                <template v-if="selectedTicket.order_number"> · pesanan {{ selectedTicket.order_number }}</template>
                            </div>
                            <p style="margin-top:8px;white-space:pre-line">{{ selectedTicket.message }}</p>
                            <div class="adm-note" style="margin-top:6px">{{ wibDateTime(selectedTicket.created_at) }}</div>
                        </div>
                        <div v-for="message in selectedTicket.messages" :key="message.id" class="adm-block" style="margin-top:8px">
                            <strong>{{ message.sender_name }} <span class="adm-note">· {{ message.sender_type === 'ADMIN' ? 'Admin' : 'Pelanggan' }}</span></strong>
                            <p style="margin-top:6px;white-space:pre-line">{{ message.message }}</p>
                            <div class="adm-note" style="margin-top:6px">{{ wibDateTime(message.created_at) }}</div>
                        </div>
                    </div>
                    <div>
                        <div class="adm-block">
                            <strong>Pelanggan</strong>
                            <dl class="adm-kv">
                                <dt>Nama</dt><dd>{{ selectedTicket.customer_name }}</dd>
                                <dt>Email</dt><dd>{{ selectedTicket.customer_email || '—' }}</dd>
                                <dt>Sumber</dt><dd>{{ sourceLabel(selectedTicket.source) }}</dd>
                                <dt>Pesanan</dt>
                                <dd>
                                    <Link v-if="selectedTicket.order_id" :href="'/admin/orders/' + selectedTicket.order_id">{{ selectedTicket.order_number }}</Link>
                                    <span v-else>—</span>
                                </dd>
                                <dt>Penanganan terakhir</dt>
                                <dd>{{ selectedTicket.handler_name ? selectedTicket.handler_name + ' · ' + wibDateTime(selectedTicket.handled_at) : 'Belum ditangani' }}</dd>
                            </dl>
                        </div>
                        <div class="adm-block" style="margin-top:8px">
                            <strong>Ubah status & balas</strong>
                            <div class="adm-grid" style="margin-top:6px">
                                <label class="adm-field">
                                    <span>Status</span>
                                    <select v-model="form.status" class="adm-select">
                                        <option value="OPEN">Menunggu</option>
                                        <option value="IN_PROGRESS">Diproses</option>
                                        <option value="RESOLVED">Selesai</option>
                                        <option value="CLOSED">Ditutup</option>
                                    </select>
                                </label>
                                <label class="adm-field">
                                    <span>Kategori</span>
                                    <select v-model="form.kind" class="adm-select">
                                        <option v-for="kind in kinds" :key="kind.code" :value="kind.code">{{ kind.label }}</option>
                                    </select>
                                </label>
                            </div>
                            <div v-if="quickReplies.length" style="display:flex;gap:4px;flex-wrap:wrap;margin:8px 0">
                                <button v-for="(reply, index) in quickReplies" :key="index" type="button" class="adm-btn sm" @click="useReply(reply)">
                                    {{ reply.length > 30 ? reply.slice(0, 30) + '…' : reply }}
                                </button>
                            </div>
                            <label class="adm-field full">
                                <span>Balasan</span>
                                <textarea v-model="form.reply" class="adm-textarea" rows="4" maxlength="5000" placeholder="Kosongkan bila hanya mengubah status" />
                            </label>
                            <div style="display:flex;justify-content:flex-end;margin-top:8px">
                                <button type="button" class="adm-btn primary" :disabled="sending" @click="send">Kirim balasan</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari tiket</span>
                    <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Nomor tiket, subjek, nama, email, atau nomor pesanan">
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="filter.status" class="adm-select">
                        <option value="">Semua status</option>
                        <option value="OPEN">Menunggu</option>
                        <option value="IN_PROGRESS">Diproses</option>
                        <option value="RESOLVED">Selesai</option>
                        <option value="CLOSED">Ditutup</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Kategori</span>
                    <select v-model="filter.kind" class="adm-select">
                        <option value="">Semua kategori</option>
                        <option v-for="kind in kinds" :key="kind.code" :value="kind.code">{{ kind.label }}</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Terapkan</button>
                    <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                </div>
                <label class="adm-field narrow">
                    <span>Sumber tiket</span>
                    <select v-model="filter.source" class="adm-select">
                        <option value="">Semua sumber</option>
                        <option value="ACCOUNT">Akun pelanggan</option>
                        <option value="GUEST">Tanpa akun</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Tiket per halaman</span>
                    <select v-model.number="filter.per_page" class="adm-select">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} tiket</option>
                    </select>
                </label>
            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Tiket</th><th>Pelanggan</th><th>Kategori</th><th>Status</th><th>Pesan</th><th>Terakhir</th><th>Tindakan</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <strong>#{{ row.id }}</strong>
                                <div class="adm-note">{{ row.subject }}</div>
                            </td>
                            <td>
                                {{ row.customer_name }}
                                <div class="adm-note">{{ row.customer_email || 'Tanpa email' }}</div>
                                <span class="adm-tag plain" style="margin-top:2px">{{ sourceLabel(row.source) }}</span>
                            </td>
                            <td>{{ kindLabel(row.kind) }}</td>
                            <td><span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span></td>
                            <td>{{ num(row.message_count) }}</td>
                            <td class="adm-note">{{ wibDateTime(row.last_message_at) }}</td>
                            <td><button type="button" class="adm-btn sm" @click="openTicket(row)">Buka</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="adm-empty">Tidak ada tiket yang sesuai filter.</p>
            <div class="adm-pager">
                <span>{{ tickets.from || 0 }}–{{ tickets.to || 0 }} dari {{ num(tickets.total) }} tiket · Halaman {{ tickets.current_page || 1 }} dari {{ tickets.last_page || 1 }}</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!tickets.prev_page_url" @click="router.get(tickets.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!tickets.next_page_url" @click="router.get(tickets.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>
    </AdminLayout>
</template>
