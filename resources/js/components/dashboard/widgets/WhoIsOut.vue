<script setup>
import { CalendarOff } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps({ data: Object, title: String });
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="CalendarOff"
            icon-class="bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"
            :subtitle="data.today_count ? `${data.today_count} out today` : 'Everyone in'" />
        <EmptyState v-if="!data.today?.length && !data.week?.length" :icon="CalendarOff" title="No one on leave" />
        <template v-else>
            <template v-if="data.today?.length">
                <p class="px-5 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Today</p>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="r in data.today" :key="r.id"
                        class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <Avatar :name="r.name" :src="r.photo_url" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ r.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ r.department }} · {{ r.type }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-slate-400">{{ r.days }}d</span>
                    </li>
                </ul>
            </template>
            <template v-if="data.week?.length">
                <p class="px-5 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Later this week</p>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="r in data.week" :key="r.id"
                        class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <Avatar :name="r.name" :src="r.photo_url" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ r.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ r.type }} · from {{ r.start_date }}</p>
                        </div>
                    </li>
                </ul>
            </template>
        </template>
    </div>
</template>
