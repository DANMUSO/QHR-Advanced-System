<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { ScrollText, Search, ChevronDown } from 'lucide-vue-next';
import api from '@/lib/api';
import { formatDateTime, titleCase } from '@/lib/format';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const filters = reactive({ search: '', log: '' });
const page = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const expanded = ref(null);
const eventTone = { created: 'green', updated: 'sky', deleted: 'red' };

async function load(p = 1) {
    loading.value = true;
    page.value = (await api.get('/admin/activity-log', { params: { ...filters, page: p } })).data;
    loading.value = false;
}

function changes(row) {
    const attrs = row.changes?.attributes ?? {};
    const old = row.changes?.old ?? {};
    return Object.keys(attrs).map((k) => ({ field: k, from: old[k], to: attrs[k] }));
}

watch(() => filters.log, () => load());
watchDebounced(() => filters.search, () => load(), { debounce: 300 });
onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-6xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Audit trail</h1>
            <p class="text-sm text-slate-500">Who changed what, and when. Records are kept for compliance and cannot be edited.</p>
        </div>

        <div class="card mb-4 grid gap-3 p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="filters.search" class="input pl-9" placeholder="Search descriptions" />
            </div>
            <select v-model="filters.log" class="input">
                <option value="">All logs</option>
                <option value="default">Data changes</option>
                <option value="security">Security</option>
            </select>
        </div>

        <div class="card divide-y divide-slate-100 dark:divide-slate-800" :class="loading && 'opacity-50'">
            <div v-for="row in page.data" :key="row.id">
                <button class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-slate-50/60 dark:hover:bg-slate-800/30" @click="expanded = expanded === row.id ? null : row.id">
                    <Badge :tone="row.log === 'security' ? 'amber' : eventTone[row.event] ?? 'slate'">{{ row.event ? titleCase(row.event) : titleCase(row.log) }}</Badge>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">
                            <span class="font-medium">{{ row.causer }}</span> — {{ row.event ? `${row.event} ${row.subject} #${row.subject_id}` : row.description }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs text-slate-500">{{ formatDateTime(row.at) }}</span>
                    <ChevronDown v-if="changes(row).length" class="size-4 shrink-0 text-slate-400 transition" :class="expanded === row.id && 'rotate-180'" />
                </button>
                <div v-if="expanded === row.id && changes(row).length" class="bg-slate-50 px-4 py-3 dark:bg-slate-800/40">
                    <table class="w-full text-xs">
                        <thead class="text-left text-slate-500"><tr><th class="pb-1 pr-4">Field</th><th class="pb-1 pr-4">Before</th><th class="pb-1">After</th></tr></thead>
                        <tbody>
                            <tr v-for="c in changes(row)" :key="c.field">
                                <td class="py-1 pr-4 font-medium">{{ titleCase(c.field) }}</td>
                                <td class="py-1 pr-4 text-rose-600 line-through decoration-rose-300">{{ c.from ?? '—' }}</td>
                                <td class="py-1 text-emerald-700 dark:text-emerald-400">{{ c.to ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <EmptyState v-if="!loading && !page.data.length" :icon="ScrollText" title="No audit records found" />
        </div>
        <div class="mt-4 flex items-center justify-between text-sm">
            <span class="text-slate-500">{{ page.total }} records</span>
            <div class="flex gap-2">
                <button class="btn-secondary !py-1.5" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">Previous</button>
                <button class="btn-secondary !py-1.5" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">Next</button>
            </div>
        </div>
    </div>
</template>
