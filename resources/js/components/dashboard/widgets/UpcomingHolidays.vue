<script setup>
import { CalendarDays } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

defineProps({ data: Object, title: String });
const month = d => new Date(d).toLocaleDateString('en-KE', { month: 'short' });
const day   = d => new Date(d).getDate();
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="CalendarDays"
            icon-class="bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400" />
        <EmptyState v-if="!data.items.length" :icon="CalendarDays" title="No upcoming holidays" />
        <ul v-else class="flex-1 divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="h in data.items" :key="h.date+h.name"
                class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <div class="flex size-11 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 dark:bg-brand-500/10">
                    <span class="text-[9px] font-bold uppercase leading-none text-brand-600 dark:text-brand-400">{{ month(h.date) }}</span>
                    <span class="text-lg font-bold leading-tight text-brand-700 dark:text-brand-300">{{ day(h.date) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ h.name }}</p>
                    <p class="text-xs text-slate-500">{{ h.weekday }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="h.days_away===0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                          : h.days_away<=7  ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                          : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'">
                    {{ h.days_away===0 ? 'Today' : `${h.days_away}d` }}
                </span>
            </li>
        </ul>
    </div>
</template>
