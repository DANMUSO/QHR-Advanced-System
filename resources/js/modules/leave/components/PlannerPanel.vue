<script setup>
/** Year at a glance (HR-FEATURES §3.5): who is off on each day, shaded by head-count, with holidays. */
import { ref, computed, onMounted } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import api from '@/lib/api';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

const year = ref(new Date().getFullYear());
const events = ref([]);
const holidays = ref([]);
const holidayNames = ref({});
const stats = ref({});
const loading = ref(false);
const selected = ref(null);
const showPending = ref(true);

const pad = (n) => String(n).padStart(2, '0');
const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
const todayIso = new Date().toLocaleDateString('en-CA');

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/calendar', { params: { from: `${year.value}-01-01`, to: `${year.value}-12-31` } });
        events.value = data.data;
        holidays.value = data.holidays ?? [];
        holidayNames.value = data.holiday_names ?? {};
        stats.value = data.stats ?? {};
    } finally { loading.value = false; }
}

const visible = computed(() => events.value.filter(e => showPending.value || ['approved', 'recalled'].includes(e.status)));

/** date => events on that day */
const byDay = computed(() => {
    const map = {};
    for (const e of visible.value) {
        const d = new Date(e.start_date + 'T00:00:00');
        const end = new Date(e.end_date + 'T00:00:00');
        while (d <= end) {
            const k = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            (map[k] ??= []).push(e);
            d.setDate(d.getDate() + 1);
        }
    }
    return map;
});
const peak = computed(() => Math.max(1, ...Object.values(byDay.value).map(l => l.length)));
const holidaySet = computed(() => new Set(holidays.value));

const months = computed(() => Array.from({ length: 12 }, (_, m) => {
    const first = new Date(year.value, m, 1);
    const lead = (first.getDay() + 6) % 7; // Monday first
    const days = new Date(year.value, m + 1, 0).getDate();
    return {
        name: first.toLocaleString('default', { month: 'long' }),
        cells: [...Array(lead).fill(null), ...Array.from({ length: days }, (_, i) => iso(year.value, m, i + 1))],
    };
}));

function cellClass(day) {
    const n = byDay.value[day]?.length ?? 0;
    const weekend = [0, 6].includes(new Date(day + 'T00:00:00').getDay());
    if (n) {
        const level = Math.ceil((n / peak.value) * 4);
        return ['bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200', 'bg-brand-200 text-brand-900 dark:bg-brand-500/35 dark:text-brand-100', 'bg-brand-400 text-white dark:bg-brand-500/60', 'bg-brand-600 text-white dark:bg-brand-500'][level - 1];
    }
    if (holidaySet.value.has(day)) return 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200';
    return weekend ? 'text-slate-300 dark:text-slate-600' : 'text-slate-600 dark:text-slate-400';
}

function shift(n) { year.value += n; selected.value = null; load(); }

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <button class="btn-secondary !p-2" aria-label="Previous year" @click="shift(-1)"><ChevronLeft class="size-4" /></button>
                <span class="min-w-16 text-center font-semibold text-slate-900 dark:text-white">{{ year }}</span>
                <button class="btn-secondary !p-2" aria-label="Next year" @click="shift(1)"><ChevronRight class="size-4" /></button>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400"><input v-model="showPending" type="checkbox" class="rounded" /> Include pending</label>
            <div class="ml-auto flex flex-wrap gap-2 text-xs">
                <Badge color="slate">Staff: {{ stats.total_staff ?? 0 }}</Badge>
                <Badge color="blue">On leave today: {{ stats.on_leave ?? 0 }}</Badge>
                <Badge color="yellow">Pending: {{ stats.pending ?? 0 }}</Badge>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else class="grid gap-6 lg:grid-cols-[1fr_18rem]">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="m in months" :key="m.name" class="card p-3">
                    <p class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ m.name }}</p>
                    <div class="grid grid-cols-7 gap-0.5 text-center text-[11px]">
                        <span v-for="(d, i) in ['M','T','W','T','F','S','S']" :key="'h' + i" class="text-slate-400">{{ d }}</span>
                        <template v-for="(day, i) in m.cells" :key="i">
                            <span v-if="!day" />
                            <button v-else type="button" class="aspect-square rounded transition hover:ring-1 hover:ring-brand-400"
                                :class="[cellClass(day), day === todayIso && 'ring-1 ring-slate-900 dark:ring-white', selected === day && 'ring-2 ring-brand-600']"
                                :title="(holidayNames[day] ? holidayNames[day] + ' · ' : '') + (byDay[day]?.length ?? 0) + ' off'"
                                @click="selected = day">
                                {{ Number(day.slice(8)) }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="card h-fit p-4 lg:sticky lg:top-4">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ selected ?? 'Choose a day' }}</p>
                <p v-if="selected && holidayNames[selected]" class="text-xs text-amber-600">{{ holidayNames[selected] }}</p>
                <div v-if="selected" class="mt-3 space-y-2">
                    <p v-if="!byDay[selected]?.length" class="text-sm text-slate-400">Nobody is off.</p>
                    <div v-for="e in byDay[selected] ?? []" :key="e.id" class="flex items-center gap-2">
                        <Avatar :src="e.photo_url" :name="e.name" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ e.name }}</p>
                            <p class="flex items-center gap-1 text-xs text-slate-500">
                                <span class="size-2 rounded-full" :style="{ background: e.color }" /> {{ e.leave_type }} · {{ e.status_label }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-slate-400">Darker days have more staff away. Amber days are public holidays.</p>
            </div>
        </div>
    </div>
</template>
