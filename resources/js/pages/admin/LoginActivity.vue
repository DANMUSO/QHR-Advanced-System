<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { watchDebounced } from '@vueuse/core';
import { ShieldCheck, Search } from 'lucide-vue-next';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const filters = reactive({ search: '', event: '', from: '', to: '' });
const page = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const events = { login: ['Signed in', 'green'], logout: ['Signed out', 'slate'], failed: ['Failed', 'red'], locked: ['Locked out', 'red'], two_factor_failed: ['2FA failed', 'amber'], impersonation_started: ['Impersonation started', 'violet'], impersonation_stopped: ['Impersonation ended', 'slate'] };
const auth = useAuthStore();
const ui = useUiStore();
const scopedCompany = computed(() => (auth.isSuperAdmin && ui.companyScope ? auth.user?.companies?.find((c) => String(c.id) === String(ui.companyScope))?.name ?? 'the selected company' : null));

async function load(p = 1) {
    loading.value = true;
    page.value = (await api.get('/admin/login-activities', { params: { ...filters, page: p } })).data;
    loading.value = false;
}

watch(() => [filters.event, filters.from, filters.to], () => load());
watchDebounced(() => filters.search, () => load(), { debounce: 300 });
onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-6xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Sign-in activity</h1>
            <p class="text-sm text-slate-500">Every sign-in, sign-out, failed attempt, lockout and impersonation across your organisation.</p>
            <p v-if="scopedCompany" class="mt-1 text-xs text-slate-400">Showing accounts of {{ scopedCompany }}. Platform administrator sign-ins and unknown-email attempts appear under “All companies” in the top bar.</p>
        </div>

        <div class="card mb-4 grid gap-3 p-4 sm:grid-cols-4">
            <div class="relative sm:col-span-2">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="filters.search" class="input pl-9" placeholder="Search email or IP address" />
            </div>
            <select v-model="filters.event" class="input">
                <option value="">All events</option>
                <option v-for="(v, k) in events" :key="k" :value="k">{{ v[0] }}</option>
            </select>
            <div class="flex gap-2">
                <input v-model="filters.from" type="date" class="input" title="From" />
                <input v-model="filters.to" type="date" class="input" title="To" />
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr><th class="px-4 py-3">User</th><th class="px-4 py-3">Event</th><th class="px-4 py-3">Device</th><th class="px-4 py-3">IP address</th><th class="px-4 py-3">When</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800" :class="loading && 'opacity-50'">
                        <tr v-for="r in page.data" :key="r.id" class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-800 dark:text-slate-100">{{ r.user?.name ?? 'Unknown user' }}</p>
                                <p class="text-xs text-slate-500">{{ r.email }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :tone="events[r.event]?.[1]">{{ events[r.event]?.[0] ?? r.event }}</Badge>
                                <Badge v-if="r.new_device" tone="amber" class="ml-1">New device</Badge>
                                <p v-if="r.impersonator" class="mt-1 text-xs text-slate-500">by {{ r.impersonator.name }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ r.browser }} · {{ r.platform }} <span class="text-xs text-slate-400">({{ r.device }})</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ r.ip_address }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ formatDateTime(r.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!loading && !page.data.length" :icon="ShieldCheck" title="No activity matches your filters" />
            <div class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                <span class="text-slate-500">{{ page.total }} records</span>
                <div class="flex gap-2">
                    <button class="btn-secondary !py-1.5" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">Previous</button>
                    <button class="btn-secondary !py-1.5" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">Next</button>
                </div>
            </div>
        </div>
    </div>
</template>
