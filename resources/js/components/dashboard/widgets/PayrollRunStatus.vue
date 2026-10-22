<script setup>
import { useRouter } from 'vue-router';
import { Wallet } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import WidgetHeader from '../WidgetHeader.vue';

defineProps({ data: Object, title: String });
const router = useRouter();

const statusColor = { draft: 'slate', calculated: 'blue', reviewed: 'yellow', approved: 'green', paid: 'emerald' };
function fmt(v) { return v != null ? 'KES ' + Number(v).toLocaleString('en-KE', { minimumFractionDigits: 0 }) : '—'; }
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Wallet"
            icon-class="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"
            to="/payroll" link-label="All runs" />
        <div v-if="!data.current" class="flex flex-1 items-center justify-center p-5 text-sm text-slate-400">
            No open payroll runs.
        </div>
        <div v-else class="flex flex-1 flex-col px-5 py-4">
            <button class="text-left" @click="router.push(`/payroll/runs/${data.current.id}`)">
                <div class="flex items-center justify-between gap-2">
                    <p class="font-semibold text-slate-900 dark:text-white">{{ data.current.period }}</p>
                    <Badge :color="statusColor[data.current.status] ?? 'slate'">{{ data.current.status }}</Badge>
                </div>
                <p v-if="data.current.needs_recalculation" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                    ⚠ Needs recalculation
                </p>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                        <p class="text-slate-400">Employees</p>
                        <p class="font-semibold text-slate-700 dark:text-slate-200">{{ data.current.employee_count }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                        <p class="text-slate-400">Net pay</p>
                        <p class="font-semibold text-slate-700 dark:text-slate-200">{{ fmt(data.current.total_net) }}</p>
                    </div>
                </div>
                <p v-if="data.current.pay_date" class="mt-2 text-xs text-slate-400">Pay date: {{ data.current.pay_date }}</p>
            </button>
            <p v-if="data.open_runs > 1" class="mt-3 text-xs text-slate-400">+{{ data.open_runs - 1 }} other open run(s)</p>
        </div>
    </div>
</template>
