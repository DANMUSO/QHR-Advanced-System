<script setup>
import { computed } from 'vue';
import { Users, UserPlus, UserMinus, TrendingDown, Cake, Hourglass, ShieldQuestion, ArrowUp, ArrowDown } from 'lucide-vue-next';
import { formatNumber } from '@/lib/format';

const props = defineProps({ data: Object });

const gTotal = computed(() => Object.values(props.data.gender || {}).reduce((a, b) => a + b, 0) || 1);
const gPct   = g => Math.round(((props.data.gender?.[g] ?? 0) / gTotal.value) * 100);

const tiles = computed(() => [
    { label: 'Total Headcount', value: formatNumber(props.data.headcount),   icon: Users,          c: 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',     delta: props.data.headcount_change },
    { label: 'Joiners (MTD)',   value: props.data.joiners_this_month,         icon: UserPlus,       c: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' },
    { label: 'Leavers (MTD)',   value: props.data.leavers_this_month,         icon: UserMinus,      c: 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' },
    { label: 'Turnover (12mo)', value: `${props.data.turnover_rate}%`,        icon: TrendingDown,   c: 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' },
    { label: 'Average Age',     value: props.data.average_age ? `${props.data.average_age} yrs` : '—', icon: Cake, c: 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400' },
    { label: 'Avg Tenure',      value: props.data.average_tenure_years ? `${props.data.average_tenure_years} yrs` : '—', icon: Hourglass, c: 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400' },
    { label: 'On Probation',    value: props.data.on_probation,               icon: ShieldQuestion, c: 'bg-teal-50 text-teal-600 dark:bg-teal-500/10 dark:text-teal-400' },
]);
</script>

<template>
    <!-- 4 cols on mobile, 8 on lg. Border grid via border-r + border-b to avoid divide-x/y breakage -->
    <div class="grid grid-cols-4 lg:grid-cols-8">
        <div v-for="(t, i) in tiles" :key="t.label"
            class="border-b border-r border-slate-100 px-5 py-5 transition hover:bg-slate-50/60 dark:border-slate-800 dark:hover:bg-slate-800/40"
            :class="{ 'border-r-0 lg:border-r': (i + 1) % 4 === 0, 'lg:border-r-0': i === 6 }">
            <div class="mb-3 flex items-start justify-between">
                <span class="inline-flex rounded-xl p-2" :class="t.c">
                    <component :is="t.icon" class="size-4" />
                </span>
                <span v-if="t.delta !== undefined"
                    class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                    :class="t.delta >= 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
                                         : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400'">
                    <ArrowUp v-if="t.delta >= 0" class="size-2.5" />
                    <ArrowDown v-else class="size-2.5" />
                    {{ Math.abs(t.delta) }}
                </span>
            </div>
            <p class="text-[26px] font-bold leading-none tracking-tight text-slate-900 dark:text-white">{{ t.value }}</p>
            <p class="mt-1.5 text-xs font-medium text-slate-500">{{ t.label }}</p>
        </div>

        <!-- Gender tile — last cell, no right border -->
        <div class="border-b border-slate-100 px-5 py-5 dark:border-slate-800">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Gender</p>
            <div class="mb-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="flex h-full">
                    <div class="bg-sky-500 transition-all duration-700" :style="{ width: gPct('male') + '%' }" />
                    <div class="bg-pink-400 transition-all duration-700" :style="{ width: gPct('female') + '%' }" />
                </div>
            </div>
            <div class="space-y-1 text-xs text-slate-600 dark:text-slate-400">
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-sky-500" /> Male</span>
                    <span class="font-semibold">{{ gPct('male') }}%</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-pink-400" /> Female</span>
                    <span class="font-semibold">{{ gPct('female') }}%</span>
                </div>
            </div>
        </div>
    </div>
</template>
