<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const panel = computed(() => page.props.adminPanel || { admin: null, menu: [], unread_notifications: 0 });
</script>

<template>
    <div class="min-h-screen bg-[#090e1b] text-slate-100">
        <div class="mx-auto flex max-w-[1600px]">
            <aside class="hidden min-h-screen w-64 shrink-0 border-r border-slate-800 bg-slate-950/90 p-4 lg:block">
                <div class="mb-5">
                    <div class="text-lg font-bold">LFAMILIA STORE</div>
                    <div class="mt-1 text-xs text-slate-500">{{ panel.admin?.name }} · {{ panel.admin?.role }}</div>
                </div>
                <nav class="space-y-1">
                    <Link
                        v-for="item in panel.menu"
                        :key="item.href"
                        :href="item.href"
                        class="block rounded-md px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-4 py-3 md:px-6">
                    <div class="lg:hidden">
                        <Link href="/admin/panel" class="font-semibold">LFAMILIA Admin</Link>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <Link v-if="panel.can_notifications" href="/admin/notifications" class="rounded-md bg-slate-800 px-3 py-2 text-sm">
                            Notifikasi<span v-if="panel.unread_notifications" class="ml-1 text-cyan-300">({{ panel.unread_notifications }})</span>
                        </Link>
                        <Link href="/" class="rounded-md bg-slate-800 px-3 py-2 text-sm">Toko</Link>
                    </div>
                </header>

                <div class="overflow-x-auto border-b border-slate-800 px-4 py-2 lg:hidden">
                    <div class="flex min-w-max gap-2">
                        <Link v-for="item in panel.menu" :key="item.href" :href="item.href" class="rounded bg-slate-800 px-3 py-2 text-xs">
                            {{ item.label }}
                        </Link>
                    </div>
                </div>

                <main class="p-4 md:p-6">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
