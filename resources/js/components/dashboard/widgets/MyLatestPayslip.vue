<script setup>
import { ref } from 'vue';
import { FileDown, Eye, EyeOff } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';

defineProps({ data: Object, title: String });

const revealed = ref(false);
function fmt(v) { return v != null ? 'KES ' + Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2 }) : '—'; }
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="FileDown"
            icon-class="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"
            to="/portal?tab=payslips" link-label="All payslips" />
        <div v-if="!data.available" class="flex flex-1 items-center justify-center p-5 text-sm text-slate-400">
            No payslips yet.
        </div>
        <div v-else class="flex flex-1 flex-col justify-center px-5 py-4">
            <p class="text-xs text-slate-500">{{ data.period_label }} · Pay date {{ data.pay_date ?? '—' }}</p>
            <div class="mt-3 flex items-end justify-between">
                <div>
                    <p class="text-xs text-slate-400">Net pay</p>
                    <p class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        <span v-if="revealed">{{ fmt(data.net_pay) }}</span>
                        <span v-else class="select-none tracking-widest text-slate-400">••••••</span>
                    </p>
                </div>
                <button class="btn-ghost !p-2" :aria-label="revealed ? 'Hide net pay' : 'Show net pay'" @click="revealed = !revealed">
                    <EyeOff v-if="revealed" class="size-4" />
                    <Eye v-else class="size-4" />
                </button>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                    <p class="text-slate-400">Gross</p>
                    <p class="font-semibold text-slate-700 dark:text-slate-200">{{ fmt(data.gross_pay) }}</p>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                    <p class="text-slate-400">PAYE</p>
                    <p class="font-semibold text-slate-700 dark:text-slate-200">{{ fmt(data.paye) }}</p>
                </div>
            </div>
            <a :href="`/api/payroll/my-payslips/${data.payslip_id}/pdf`" target="_blank"
                class="mt-3 flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                <FileDown class="size-3.5" /> Download PDF
            </a>
        </div>
    </div>
</template>
