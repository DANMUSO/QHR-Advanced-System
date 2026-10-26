<script>
import api from '@/lib/api';

// Shared per-page-load cache so many pickers on one screen make one request per kind.
const cache = new Map();

const SOURCES = {
    employees: { lookup: 'employees', label: (r) => r.name, sub: (r) => r.employee_number },
    supervisors: { lookup: 'supervisors', label: (r) => r.name },
    departments: { lookup: 'departments', label: (r) => r.name },
    positions: { lookup: 'positions', label: (r) => r.title },
    branches: { lookup: 'branches', label: (r) => r.name },
    levels: { lookup: 'levels', label: (r) => r.name },
    applicants: {
        load: async () => (await api.get('/recruitment/applicants', { params: { per_page: 500 } })).data.data ?? [],
        label: (r) => r.name,
        sub: (r) => [r.posting_title, r.stage].filter(Boolean).join(' · '),
    },
};

export function loadOptions(kind) {
    if (!cache.has(kind)) {
        const src = SOURCES[kind];
        const p = src.load
            ? src.load()
            : api.get('/lookups', { params: { only: src.lookup } }).then((r) => r.data[src.lookup] ?? []);
        cache.set(kind, p.catch((e) => { cache.delete(kind); throw e; }));
    }
    return cache.get(kind);
}
</script>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { onClickOutside } from '@vueuse/core';
import { ChevronDown, X, Search } from 'lucide-vue-next';

/**
 * Searchable picker backed by /api/lookups (or recruitment applicants).
 * <LookupSelect v-model="form.employee_id" kind="employees" placeholder="Choose an employee" />
 */
const props = defineProps({
    kind: { type: String, required: true },
    placeholder: { type: String, default: 'Select…' },
    id: String,
    error: Boolean,
    disabled: Boolean,
    clearable: { type: Boolean, default: true },
    // ids that may not be picked (e.g. yourself as your own handover colleague)
    exclude: { type: Array, default: () => [] },
});
const model = defineModel({ type: [Number, String, null], default: '' });

const options = ref([]);
const loading = ref(true);
const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref(null);
const searchInput = ref(null);
const src = SOURCES[props.kind] ?? SOURCES.employees;

onClickOutside(root, () => (open.value = false));

onMounted(async () => {
    try { options.value = await loadOptions(props.kind); } catch { options.value = []; }
    finally { loading.value = false; }
});

const selected = computed(() => options.value.find((o) => String(o.id) === String(model.value)));
const filtered = computed(() => {
    const q = query.value.toLowerCase().trim();
    const excluded = new Set(props.exclude.filter((v) => v !== null && v !== '').map(String));
    const pool = excluded.size ? options.value.filter((o) => !excluded.has(String(o.id))) : options.value;
    const list = q ? pool.filter((o) => `${src.label(o)} ${src.sub?.(o) ?? ''}`.toLowerCase().includes(q)) : pool;
    return list.slice(0, 100);
});
watch(filtered, () => (active.value = 0));

async function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        query.value = '';
        await nextTick();
        searchInput.value?.focus();
    }
}

function choose(o) {
    model.value = o.id;
    open.value = false;
}

function onKey(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(active.value + 1, filtered.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(active.value - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); if (filtered.value[active.value]) choose(filtered.value[active.value]); }
    else if (e.key === 'Escape') { open.value = false; }
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            :id="id"
            type="button"
            class="input flex items-center justify-between gap-2 text-left"
            :class="[error && 'border-red-500', disabled && 'cursor-not-allowed opacity-60']"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :disabled="disabled"
            @click="toggle"
        >
            <span v-if="selected" class="truncate text-slate-900 dark:text-slate-100">
                {{ src.label(selected) }}
                <span v-if="src.sub?.(selected)" class="text-slate-400"> · {{ src.sub(selected) }}</span>
            </span>
            <span v-else class="truncate text-slate-400">{{ loading ? 'Loading…' : placeholder }}</span>
            <span class="flex shrink-0 items-center gap-1 text-slate-400">
                <X v-if="clearable && selected && !disabled" class="size-4 hover:text-slate-600" aria-label="Clear" @click.stop="model = ''" />
                <ChevronDown class="size-4" />
            </span>
        </button>

        <div v-if="open" class="card absolute z-50 mt-1 w-full overflow-hidden shadow-lg" role="listbox">
            <div class="relative border-b border-slate-100 dark:border-slate-800">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input ref="searchInput" v-model="query" class="w-full bg-transparent py-2.5 pl-9 pr-3 text-sm outline-none" placeholder="Type to search…" aria-label="Search" @keydown="onKey" />
            </div>
            <ul class="scrollbar-thin max-h-60 overflow-y-auto py-1">
                <li v-if="!filtered.length" class="px-3 py-3 text-center text-sm text-slate-500">
                    {{ loading ? 'Loading…' : query ? `No matches for “${query}”` : 'Nothing available' }}
                </li>
                <li
                    v-for="(o, i) in filtered"
                    :key="o.id"
                    role="option"
                    :aria-selected="String(o.id) === String(model)"
                    class="cursor-pointer px-3 py-2 text-sm"
                    :class="[i === active ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'text-slate-700 dark:text-slate-300', String(o.id) === String(model) && 'font-semibold']"
                    @mouseenter="active = i"
                    @click="choose(o)"
                >
                    {{ src.label(o) }}
                    <span v-if="src.sub?.(o)" class="block text-xs text-slate-400">{{ src.sub(o) }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
