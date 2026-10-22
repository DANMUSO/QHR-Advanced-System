<script setup>
import { Receipt } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';

defineProps({ data: Object, title: String });
function fmt(v) { return v != null ? 'KES ' + Number(v).toLocaleString('en-KE', { minimumFractionDigits: 0 }) : null; }
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Receipt"
            icon-class="bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400" />
        <div v-if="!data.available" class="flex flex-1 items-center justify-center p-5 text-sm text-slate-400">
            No approved payroll runs yet.
        </div>
        <div v-else class="flex flex-1 flex-col px-5 py-4">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm text-slate-600 dark:text-slate-400">{{ data.run_title }}</p>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="data.overdue ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'
                          : data.days_left <= 3 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'
                          : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'">
                    {{ data.overdue ? 'Overdue' : `Due in ${data.days_left}d` }}
                </span>
            </div>
            <p class="mb-3 text-xs text-slate-400">Due date: <strong class="text-slate-600 dark:text-slate-300">{{ data.due_date }}</strong></p>
            <ul class="space-y-2">
                <li v-for="r in data.remittances" :key="r.name"
                    class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/50">
                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ r.name }}</span>
                    <span class="text-slate-500">{{ fmt(r.amount) ?? 'See return' }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
