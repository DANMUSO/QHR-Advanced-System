<script setup>
import { HeartPulse } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';

defineProps({ data: Object, title: String });

function fmt(v) { return v != null ? Number(v).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) : '—'; }
function pct(used, limit) { return limit > 0 ? Math.min(100, Math.round(used / limit * 100)) : 0; }
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="HeartPulse"
            icon-class="bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"
            to="/claims" link-label="My claims" />
        <div class="flex flex-1 flex-col justify-center gap-4 px-5 py-4">
            <!-- Annual -->
            <div>
                <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                    <span>Annual ({{ data.year }})</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-100">KES {{ fmt(data.annual_available ?? data.annual_balance) }} available</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-rose-500 transition-all duration-700"
                        :style="{ width: pct(data.annual_used + (data.annual_pending || 0), data.annual_limit) + '%' }" />
                </div>
                <div class="mt-0.5 flex justify-between text-[11px] text-slate-400">
                    <span>KES {{ fmt(data.annual_used) }} used<template v-if="data.annual_pending > 0"> · {{ fmt(data.annual_pending) }} pending</template></span>
                    <span>of KES {{ fmt(data.annual_limit) }}</span>
                </div>
            </div>
            <!-- Monthly -->
            <div>
                <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                    <span>{{ data.month_label }}</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-100">KES {{ fmt(data.monthly_available ?? data.monthly_balance) }} available</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full transition-all duration-700"
                        :class="pct(data.monthly_used + (data.monthly_pending || 0), data.monthly_limit) >= 90 ? 'bg-red-500' : 'bg-amber-400'"
                        :style="{ width: pct(data.monthly_used + (data.monthly_pending || 0), data.monthly_limit) + '%' }" />
                </div>
                <div class="mt-0.5 flex justify-between text-[11px] text-slate-400">
                    <span>KES {{ fmt(data.monthly_used) }} used<template v-if="data.monthly_pending > 0"> · {{ fmt(data.monthly_pending) }} pending</template></span>
                    <span>of KES {{ fmt(data.monthly_limit) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
