<script setup>
import { Target } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import WidgetHeader from '../WidgetHeader.vue';

defineProps({ data: Object, title: String });

const statusColor = { cascaded: 'yellow', in_progress: 'blue', submitted: 'purple', approved: 'green', under_review: 'orange' };
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Target"
            icon-class="bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400"
            to="/portal?tab=goals" link-label="My goals" />
        <div class="flex flex-1 flex-col px-5 py-4">
            <!-- Progress ring summary -->
            <div class="mb-4 flex items-center gap-4">
                <div class="relative size-16 shrink-0">
                    <svg class="size-16 -rotate-90" viewBox="0 0 64 64">
                        <circle cx="32" cy="32" r="26" fill="none" stroke-width="6" class="stroke-slate-100 dark:stroke-slate-800" />
                        <circle cx="32" cy="32" r="26" fill="none" stroke-width="6" stroke-linecap="round"
                            stroke="#6366f1"
                            :stroke-dasharray="2 * Math.PI * 26"
                            :stroke-dashoffset="2 * Math.PI * 26 * (1 - data.pct / 100)"
                            class="transition-all duration-700" />
                    </svg>
                    <span class="absolute inset-0 flex items-center justify-center text-sm font-bold text-slate-900 dark:text-white">{{ data.pct }}%</span>
                </div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    <span class="text-slate-400">Total</span><span class="font-semibold">{{ data.total }}</span>
                    <span class="text-slate-400">Approved</span><span class="font-semibold text-green-600">{{ data.approved }}</span>
                    <span class="text-slate-400">Pending</span><span class="font-semibold text-blue-600">{{ data.pending }}</span>
                    <span v-if="data.overdue" class="text-slate-400">Overdue</span>
                    <span v-if="data.overdue" class="font-semibold text-red-500">{{ data.overdue }}</span>
                </div>
            </div>
            <!-- Top 5 goals -->
            <ul v-if="data.items?.length" class="space-y-1.5">
                <li v-for="g in data.items" :key="g.id" class="flex items-center gap-2 text-xs">
                    <Badge :color="statusColor[g.status] ?? 'slate'" class="shrink-0">{{ g.status }}</Badge>
                    <span class="flex-1 truncate text-slate-700 dark:text-slate-300">{{ g.name }}</span>
                    <span v-if="g.due_date" class="shrink-0 text-slate-400">{{ g.due_date }}</span>
                </li>
            </ul>
            <p v-else class="text-xs text-slate-400">No active goals.</p>
        </div>
    </div>
</template>
