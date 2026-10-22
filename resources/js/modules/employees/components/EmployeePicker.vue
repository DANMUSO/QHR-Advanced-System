<script setup>
import { ref, watch } from 'vue';
import { Search, X } from 'lucide-vue-next';
import api from '@/lib/api';
import Avatar from '@/components/ui/Avatar.vue';

/** Type-ahead picker over the employee directory. Emits the selected summary row (or null). */
const props = defineProps({
    modelValue: { type: Object, default: null },
    status: { type: String, default: 'active,suspended' },
    placeholder: { type: String, default: 'Search by name, number or email…' },
});
const emit = defineEmits(['update:modelValue']);

const q = ref('');
const results = ref([]);
const open = ref(false);
let timer;

watch(q, (v) => {
    clearTimeout(timer);
    if (!v.trim()) { results.value = []; return; }
    timer = setTimeout(async () => {
        try {
            const { data } = await api.get('/employees', { params: { search: v, status: props.status, per_page: 10 } });
            results.value = data.data ?? [];
            open.value = true;
        } catch { results.value = []; }
    }, 250);
});

function pick(e) {
    emit('update:modelValue', e);
    q.value = '';
    results.value = [];
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <div v-if="modelValue" class="flex items-center gap-3 rounded-xl border border-slate-200 p-2.5 dark:border-slate-700">
            <Avatar :name="modelValue.full_name" :src="modelValue.photo_url" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ modelValue.full_name }}</p>
                <p class="truncate text-xs text-slate-500">{{ modelValue.employee_number }} · {{ modelValue.position?.title ?? '—' }}</p>
            </div>
            <button type="button" class="btn-ghost !p-1.5" title="Change" @click="emit('update:modelValue', null)"><X class="size-4" /></button>
        </div>
        <template v-else>
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input v-model="q" class="input !pl-9" :placeholder="placeholder" @focus="open = results.length > 0" />
            <ul v-if="open && results.length" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                <li v-for="e in results" :key="e.id">
                    <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" @click="pick(e)">
                        <Avatar :name="e.full_name" :src="e.photo_url" size="xs" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm text-slate-900 dark:text-white">{{ e.full_name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ e.employee_number }} · {{ e.department?.name ?? '—' }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>
