<script setup>
import { computed, ref } from 'vue';
import { ChevronUp, ChevronDown, Download, Inbox } from 'lucide-vue-next';
import { downloadCsv, getPath } from '@/lib/export';
import { label, isRawValue } from '@/lib/format';
import EmptyState from './EmptyState.vue';

/**
 * Generic table.
 *   columns: [{ key, label, sortable?, align?: 'right'|'center', class?, format?: (value,row)=>string, export?: false }]
 *     Status-like raw values (e.g. `pending_approval`, `mpesa`) are shown via label() unless the column sets `raw: true`.
 *   rows: array
 *   paginator: Laravel paginator object ({ current_page, last_page, total, from, to }) — enables server paging (emits 'page')
 *   Sorting: client-side without a paginator; with one, handle the emitted 'sort' ({ key, dir }) server-side.
 * Custom cells: <template #cell-name="{ row, value }">…</template>
 * Toolbar (filters): <template #toolbar>…</template>; row actions: <template #actions="{ row }">…</template>
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    loading: Boolean,
    paginator: Object,
    exportName: String,          // shows a CSV button exporting the visible rows
    emptyTitle: { type: String, default: 'Nothing to show' },
    emptyDescription: String,
    rowKey: { type: String, default: 'id' },
    clickable: Boolean,
});
const emit = defineEmits(['page', 'sort', 'row-click']);

const sortKey = ref(null);
const sortDir = ref('asc');

function toggleSort(col) {
    if (!col.sortable) return;
    sortDir.value = sortKey.value === col.key && sortDir.value === 'asc' ? 'desc' : 'asc';
    sortKey.value = col.key;
    emit('sort', { key: col.key, dir: sortDir.value });
}

const displayRows = computed(() => {
    if (!sortKey.value || props.paginator) return props.rows;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...props.rows].sort((a, b) => {
        const x = getPath(a, sortKey.value);
        const y = getPath(b, sortKey.value);
        if (x == null) return 1;
        if (y == null) return -1;
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * dir;
    });
});

// Columns whose lower-case values are codes rather than free text.
const STATUS_KEY = /(status|state|type|method|category|kind|source|stage|frequency|mode|channel|basis|reason|priority|outcome|decision|event|action|period|level)$/i;

function cellValue(col, row) {
    const v = getPath(row, col.key);
    if (col.format) return col.format(v, row);
    if (v === null || v === undefined || v === '') return '—';
    return !col.raw && isRawValue(v) && (STATUS_KEY.test(col.key) || v.includes('_')) ? label(v) : v;
}

function exportCsv() {
    downloadCsv(props.exportName, props.columns.filter((c) => c.export !== false && c.key !== 'actions').map((c) => ({
        key: c.key, label: c.label, value: (r) => (c.format ? c.format(getPath(r, c.key), r) : getPath(r, c.key)),
    })), displayRows.value);
}

const align = (c) => (c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : 'text-left');
</script>

<template>
    <div class="card overflow-hidden">
        <div v-if="$slots.toolbar || exportName" class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <div class="flex flex-1 flex-wrap items-center gap-3"><slot name="toolbar" /></div>
            <button v-if="exportName" class="btn-secondary !py-2" :disabled="!rows.length" @click="exportCsv"><Download class="size-4" /> Export</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                    <tr>
                        <th v-for="c in columns" :key="c.key" class="whitespace-nowrap px-4 py-3 font-semibold" :class="[align(c), c.sortable && 'cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-300']" @click="toggleSort(c)">
                            <span class="inline-flex items-center gap-1">
                                {{ c.label }}
                                <template v-if="c.sortable && sortKey === c.key">
                                    <ChevronUp v-if="sortDir === 'asc'" class="size-3.5" />
                                    <ChevronDown v-else class="size-3.5" />
                                </template>
                            </span>
                        </th>
                        <th v-if="$slots.actions" class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template v-if="loading && !rows.length">
                        <tr v-for="i in 5" :key="'s' + i">
                            <td v-for="c in columns" :key="c.key" class="px-4 py-3"><div class="h-4 animate-pulse rounded bg-slate-100 dark:bg-slate-800" /></td>
                            <td v-if="$slots.actions" />
                        </tr>
                    </template>
                    <tr
                        v-for="row in displayRows"
                        v-else
                        :key="getPath(row, rowKey)"
                        class="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                        :class="[loading && 'opacity-60', clickable && 'cursor-pointer']"
                        @click="clickable && emit('row-click', row)"
                    >
                        <td v-for="c in columns" :key="c.key" class="px-4 py-3" :class="[align(c), c.class]">
                            <slot :name="`cell-${c.key}`" :row="row" :value="getPath(row, c.key)">{{ cellValue(c, row) }}</slot>
                        </td>
                        <td v-if="$slots.actions" class="whitespace-nowrap px-4 py-3 text-right" @click.stop>
                            <slot name="actions" :row="row" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!loading && !rows.length" :icon="Inbox" :title="emptyTitle" :description="emptyDescription" />
        <div v-if="paginator && paginator.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
            <span class="text-slate-500">{{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}</span>
            <div class="flex gap-2">
                <button class="btn-secondary !py-1.5" :disabled="paginator.current_page <= 1" @click="emit('page', paginator.current_page - 1)">Previous</button>
                <button class="btn-secondary !py-1.5" :disabled="paginator.current_page >= paginator.last_page" @click="emit('page', paginator.current_page + 1)">Next</button>
            </div>
        </div>
        <div v-else-if="paginator" class="border-t border-slate-100 px-4 py-3 text-sm text-slate-500 dark:border-slate-800">{{ paginator.total }} record{{ paginator.total === 1 ? '' : 's' }}</div>
    </div>
</template>
