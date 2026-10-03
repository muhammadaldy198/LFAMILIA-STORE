<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    tickets: { type: Object, default: () => ({ data: [], links: [] }) },
    selectedTicket: { type: Object, default: null },
    quickReplies: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const page = usePage();
const quickRepliesOpen = ref(false);
const filters = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    kind: props.filters?.kind || '',
    source: props.filters?.source || '',
    per_page: Number(props.filters?.per_page || 25),
});

const ticketForm = useForm({
    status: props.selectedTicket?.status || 'OPEN',
    kind: props.selectedTicket?.kind || 'GENERAL',
    reply: '',
});

const quickRepliesForm = useForm({
    replies: [...props.quickReplies],
});

watch(() => props.selectedTicket, (ticket) => {
    ticketForm.clearErrors();
    ticketForm.status = ticket?.status || 'OPEN';
    ticketForm.kind = ticket?.kind || 'GENERAL';
    ticketForm.reply = '';
});

watch(() => props.quickReplies, (value) => {
    quickRepliesForm.replies = [...(value || [])];
});

const menu = computed(() => page.props.adminPanel?.menu || []);
const canOrders = computed(() => menu.value.some((item) => item.href === '/admin/orders'));
const canCustomers = computed(() => menu.value.some((item) => item.href === '/admin/customers'));

const kindLabel = (code) => props.kinds.find((item) => item.code === code)?.label || code;
const statusLabel = (status) => ({
    OPEN: 'Menunggu',
    IN_PROGRESS: 'Diproses',
    RESOLVED: 'Selesai',
    CLOSED: 'Ditutup',
}[status] || status);
const statusVariant = (status) => status === 'OPEN'
    ? 'destructive'
    : status === 'IN_PROGRESS'
        ? 'secondary'
        : 'outline';
const sourceLabel = (source) => source === 'GUEST' ? 'Guest' : 'Akun';
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : '—';

function cleanQuery(extra = {}) {
    const params = new URLSearchParams();
    const values = { ...filters, ...extra };
    Object.entries(values).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) params.set(key, String(value));
    });
    return params;
}

function submitFilters() {
    router.get('/admin/support', Object.fromEntries(cleanQuery()), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilters() {
    Object.assign(filters, { q: '', status: '', kind: '', source: '', per_page: 25 });
    router.get('/admin/support', {}, { preserveState: true, preserveScroll: true });
}

function ticketHref(id) {
    const params = cleanQuery({ ticket: id });
    return '/admin/support?' + params.toString();
}

function closeTicketHref() {
    const params = cleanQuery();
    params.delete('ticket');
    const query = params.toString();
    return '/admin/support' + (query ? '?' + query : '');
}

function useQuickReply(reply) {
    ticketForm.reply = ticketForm.reply.trim()
        ? ticketForm.reply.trimEnd() + '\n\n' + reply
        : reply;
}

function saveTicket() {
    if (!props.selectedTicket) return;
    ticketForm.put('/admin/support/' + props.selectedTicket.id, {
        preserveScroll: true,
        onSuccess: () => {
            ticketForm.reply = '';
        },
    });
}

function addQuickReply() {
    if (quickRepliesForm.replies.length >= 30) return;
    quickRepliesForm.replies.push('');
}

function saveQuickReplies() {
    quickRepliesForm.put('/admin/support/quick-replies', {
        preserveScroll: true,
    });
}
</script>

<template>
<Head title="Layanan Pelanggan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Layanan Pelanggan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Tangani tiket bantuan, komplain, pembayaran, pengembalian dana, pesanan, dan percakapan pelanggan dari satu tempat.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
                <Button variant="outline" @click="quickRepliesOpen = !quickRepliesOpen">
                    {{ quickRepliesOpen ? 'Tutup balasan cepat' : 'Atur balasan cepat' }}
                </Button>
            </div>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total tiket</p><p class="mt-2 text-2xl font-semibold">{{ summary.total || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Menunggu</p><p class="mt-2 text-2xl font-semibold">{{ summary.open || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Diproses</p><p class="mt-2 text-2xl font-semibold">{{ summary.in_progress || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Selesai</p><p class="mt-2 text-2xl font-semibold">{{ summary.resolved || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Ditutup</p><p class="mt-2 text-2xl font-semibold">{{ summary.closed || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Dari akun</p><p class="mt-2 text-2xl font-semibold">{{ summary.account || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Guest</p><p class="mt-2 text-2xl font-semibold">{{ summary.guest || 0 }}</p></Card>
        </div>

        <Card v-if="quickRepliesOpen" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Balasan Cepat</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Maksimal 30 template. Template kosong otomatis diabaikan saat disimpan.</p>
                </div>
                <Button size="sm" variant="outline" :disabled="quickRepliesForm.replies.length >= 30" @click="addQuickReply">Tambah template</Button>
            </div>

            <form class="mt-4 space-y-3" @submit.prevent="saveQuickReplies">
                <div v-for="(reply, index) in quickRepliesForm.replies" :key="index" class="flex items-start gap-2">
                    <Textarea v-model="quickRepliesForm.replies[index]" maxlength="1000" rows="2" :placeholder="'Balasan cepat ' + (index + 1)" class="min-w-0 flex-1" />
                    <Button type="button" size="sm" variant="outline" @click="quickRepliesForm.replies.splice(index, 1)">Hapus</Button>
                </div>
                <p v-if="quickRepliesForm.errors.replies" class="text-sm text-destructive">{{ quickRepliesForm.errors.replies }}</p>
                <div class="flex justify-end">
                    <Button :disabled="quickRepliesForm.processing">{{ quickRepliesForm.processing ? 'Menyimpan…' : 'Simpan balasan cepat' }}</Button>
                </div>
            </form>
        </Card>

        <Card class="p-4">
            <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-6" @submit.prevent="submitFilters">
                <label class="space-y-1 md:col-span-2">
                    <span class="text-sm font-medium">Cari tiket</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Nomor tiket, pelanggan, email, subjek, atau invoice" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Status</span>
                    <select v-model="filters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua status</option>
                        <option value="OPEN">Menunggu</option>
                        <option value="IN_PROGRESS">Diproses</option>
                        <option value="RESOLVED">Selesai</option>
                        <option value="CLOSED">Ditutup</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Kategori</span>
                    <select v-model="filters.kind" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua kategori</option>
                        <option v-for="kind in kinds" :key="kind.code" :value="kind.code">{{ kind.label }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Sumber</span>
                    <select v-model="filters.source" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua sumber</option>
                        <option value="ACCOUNT">Akun pelanggan</option>
                        <option value="GUEST">Guest</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Baris</span>
                    <select v-model.number="filters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
                    <Button size="sm">Terapkan</Button>
                    <Button type="button" size="sm" variant="outline" @click="resetFilters">Reset</Button>
                </div>
            </form>
        </Card>

        <Card v-if="selectedTicket" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold">Tiket #{{ selectedTicket.id }}</h2>
                        <Badge :variant="statusVariant(selectedTicket.status)">{{ statusLabel(selectedTicket.status) }}</Badge>
                        <Badge variant="outline">{{ kindLabel(selectedTicket.kind) }}</Badge>
                        <Badge variant="outline">{{ sourceLabel(selectedTicket.source) }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ selectedTicket.subject }}</p>
                </div>
                <Link :href="closeTicketHref()" preserve-scroll class="inline-flex h-9 items-center rounded-md border px-3 text-sm">Tutup detail</Link>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
                <div class="space-y-3">
                    <article class="rounded-md border p-3">
                        <div class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                            <strong class="text-foreground">{{ selectedTicket.customer_name }}</strong>
                            <span>{{ date(selectedTicket.created_at) }}</span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-6">{{ selectedTicket.message }}</p>
                    </article>

                    <article
                        v-for="message in selectedTicket.messages"
                        :key="message.id"
                        class="rounded-md border p-3"
                        :class="message.sender_type === 'ADMIN' ? 'bg-muted/35' : ''"
                    >
                        <div class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                            <strong class="text-foreground">{{ message.sender_name }}</strong>
                            <span>{{ message.sender_type === 'ADMIN' ? 'Admin' : 'Pelanggan' }} · {{ date(message.created_at) }}</span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-6">{{ message.message }}</p>
                    </article>

                    <form class="space-y-3 rounded-md border p-3" @submit.prevent="saveTicket">
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="space-y-1">
                                <span class="text-sm font-medium">Status tiket</span>
                                <select v-model="ticketForm.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                                    <option value="OPEN">Menunggu</option>
                                    <option value="IN_PROGRESS">Diproses</option>
                                    <option value="RESOLVED">Selesai</option>
                                    <option value="CLOSED">Ditutup</option>
                                </select>
                            </label>
                            <label class="space-y-1">
                                <span class="text-sm font-medium">Kategori</span>
                                <select v-model="ticketForm.kind" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                                    <option v-for="kind in kinds" :key="kind.code" :value="kind.code">{{ kind.label }}</option>
                                </select>
                            </label>
                        </div>

                        <div v-if="quickReplies.length" class="flex flex-wrap gap-2">
                            <Button v-for="(reply, index) in quickReplies" :key="index" type="button" size="sm" variant="outline" @click="useQuickReply(reply)">
                                {{ reply.length > 55 ? reply.slice(0, 55) + '…' : reply }}
                            </Button>
                        </div>

                        <label class="block space-y-1">
                            <span class="text-sm font-medium">Balasan ke pelanggan</span>
                            <Textarea v-model="ticketForm.reply" rows="5" maxlength="5000" placeholder="Tulis balasan. Boleh dikosongkan jika hanya mengubah status atau kategori." />
                            <span v-if="ticketForm.errors.reply" class="text-xs text-destructive">{{ ticketForm.errors.reply }}</span>
                        </label>

                        <div class="flex justify-end">
                            <Button :disabled="ticketForm.processing">{{ ticketForm.processing ? 'Menyimpan…' : 'Simpan / Kirim Balasan' }}</Button>
                        </div>
                    </form>
                </div>

                <div class="space-y-3">
                    <Card class="p-4">
                        <p class="text-xs text-muted-foreground">Pelanggan</p>
                        <strong class="mt-1 block text-sm">{{ selectedTicket.customer_name }}</strong>
                        <p class="mt-1 break-all text-xs text-muted-foreground">{{ selectedTicket.customer_email || 'Email tidak tersedia' }}</p>
                        <Link v-if="selectedTicket.user_id && canCustomers" :href="'/admin/customers/' + selectedTicket.user_id" class="mt-3 inline-flex text-sm font-medium underline">Buka pelanggan</Link>
                    </Card>
                    <Card class="p-4">
                        <p class="text-xs text-muted-foreground">Pesanan terkait</p>
                        <strong class="mt-1 block text-sm">{{ selectedTicket.order_number || 'Tidak terkait pesanan' }}</strong>
                        <Link v-if="selectedTicket.order_id && canOrders" :href="'/admin/orders/' + selectedTicket.order_id" class="mt-3 inline-flex text-sm font-medium underline">Buka pesanan</Link>
                    </Card>
                    <Card class="p-4">
                        <p class="text-xs text-muted-foreground">Penanganan terakhir</p>
                        <strong class="mt-1 block text-sm">{{ selectedTicket.handler_name || 'Belum ditangani admin' }}</strong>
                        <p class="mt-1 text-xs text-muted-foreground">{{ selectedTicket.handled_at ? date(selectedTicket.handled_at) : '—' }}</p>
                    </Card>
                </div>
            </div>
        </Card>

        <Card class="min-w-0 p-4">
            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tiket</TableHead>
                            <TableHead>Pelanggan</TableHead>
                            <TableHead>Kategori</TableHead>
                            <TableHead>Pesanan</TableHead>
                            <TableHead>Pesan</TableHead>
                            <TableHead>Penanganan</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="ticket in tickets.data" :key="ticket.id">
                            <TableCell>
                                <strong class="block">#{{ ticket.id }}</strong>
                                <span class="text-xs text-muted-foreground">{{ date(ticket.updated_at) }}</span>
                            </TableCell>
                            <TableCell>
                                <strong class="block">{{ ticket.customer_name }}</strong>
                                <span class="block max-w-52 truncate text-xs text-muted-foreground">{{ ticket.customer_email || 'Email tidak tersedia' }}</span>
                                <Badge variant="outline" class="mt-1">{{ sourceLabel(ticket.source) }}</Badge>
                            </TableCell>
                            <TableCell>{{ kindLabel(ticket.kind) }}</TableCell>
                            <TableCell>{{ ticket.order_number || '—' }}</TableCell>
                            <TableCell>
                                <strong class="block max-w-64 truncate">{{ ticket.subject }}</strong>
                                <span class="text-xs text-muted-foreground">{{ ticket.message_count }} pesan</span>
                            </TableCell>
                            <TableCell>
                                <span>{{ ticket.handler_name || 'Belum ditangani' }}</span>
                                <p class="text-xs text-muted-foreground">{{ ticket.handled_at ? date(ticket.handled_at) : '—' }}</p>
                            </TableCell>
                            <TableCell><Badge :variant="statusVariant(ticket.status)">{{ statusLabel(ticket.status) }}</Badge></TableCell>
                            <TableCell class="text-right">
                                <Link :href="ticketHref(ticket.id)" preserve-scroll class="inline-flex h-9 items-center rounded-md border px-3 text-sm font-medium">Buka</Link>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!tickets.data?.length">
                            <TableCell colspan="8" class="py-10 text-center text-muted-foreground">Tidak ada tiket sesuai filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="tickets.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                <Link
                    v-for="link in tickets.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    preserve-scroll
                    :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']"><span v-html="link.label" /></Link>
            </div>
        </Card>
    </div>
</AdminShell>
</template>
