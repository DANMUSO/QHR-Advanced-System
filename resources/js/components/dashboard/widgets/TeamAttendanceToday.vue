<script setup>
import { ClipboardList } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps({ data: Object, title: String });

const style = {
    present:  { label:'Present',  dot:'bg-emerald-500', badge:'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' },
    late:     { label:'Late',     dot:'bg-amber-500',   badge:'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' },
    absent:   { label:'Absent',   dot:'bg-rose-500',    badge:'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' },
    on_leave: { label:'On Leave', dot:'bg-sky-500',     badge:'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' },
    not_in:   { label:'Not In',   dot:'bg-slate-400',   badge:'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    day_off:  { label:'Day Off',  dot:'bg-slate-300',   badge:'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-500' },
};
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="ClipboardList"
            icon-class="bg-teal-50 text-teal-600 dark:bg-teal-500/10 dark:text-teal-400"
            :subtitle="data.total ? `${data.in_count}/${data.total} in · ${data.attendance_rate}%` : 'No team'" />
        <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
            <template v-for="(group, status) in data.groups" :key="status">
                <div v-if="group.count > 0">
                    <div class="mb-2 flex items-center gap-2">
                        <span class="flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="style[status]?.badge ?? 'bg-slate-100 text-slate-600'">
                            <span class="size-1.5 rounded-full" :class="style[status]?.dot ?? 'bg-slate-400'" />
                            {{ style[status]?.label ?? status }} · {{ group.count }}
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <div v-for="p in group.people" :key="p.id"
                            class="flex items-center gap-1.5 rounded-full border border-slate-100 bg-slate-50 px-2.5 py-1 dark:border-slate-700 dark:bg-slate-800">
                            <Avatar :name="p.name" :src="p.photo_url" size="xs" />
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ p.name.split(' ')[0] }}</span>
                            <span v-if="p.first_in" class="text-xs text-slate-400">{{ p.first_in }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
