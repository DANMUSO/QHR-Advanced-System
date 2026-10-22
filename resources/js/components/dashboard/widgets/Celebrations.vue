<script setup>
import { PartyPopper, Cake, Award } from 'lucide-vue-next';
import { formatDate } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps({ data: Object, title: String });
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="PartyPopper"
            icon-class="bg-pink-50 text-pink-600 dark:bg-pink-500/10 dark:text-pink-400"
            subtitle="Next 14 days" />
        <EmptyState v-if="!data.items.length" :icon="Cake" title="No celebrations coming up" />
        <ul v-else class="scrollbar-thin flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <li v-for="c in data.items" :key="c.type+c.employee.id"
                class="flex items-center gap-3 px-5 py-3 transition"
                :class="c.is_today ? 'bg-amber-50 dark:bg-amber-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'">
                <Avatar :name="c.employee.name" :src="c.employee.photo_url" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ c.employee.name }}</p>
                    <p class="flex items-center gap-1 text-xs text-slate-500">
                        <component :is="c.type==='birthday' ? Cake : Award" class="size-3.5"
                            :class="c.type==='birthday' ? 'text-pink-500' : 'text-amber-500'" />
                        {{ c.type==='birthday' ? 'Birthday' : `${c.years} yr${c.years>1?'s':''} at work` }}
                    </p>
                </div>
                <span class="shrink-0 text-xs font-medium"
                    :class="c.is_today ? 'text-amber-600' : 'text-slate-400'">
                    {{ c.is_today ? 'Today 🎉' : formatDate(c.date, { day:'numeric', month:'short' }) }}
                </span>
            </li>
        </ul>
    </div>
</template>
