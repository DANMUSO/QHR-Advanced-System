<script setup>
import { Users, Mail, Phone } from 'lucide-vue-next';
import WidgetHeader from '../WidgetHeader.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps({ data: Object, title: String });
const dot = { available:'bg-emerald-500', on_leave:'bg-amber-500', absent:'bg-rose-500' };
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Users"
            icon-class="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"
            :subtitle="`${data.count} direct report${data.count===1?'':'s'}`" />
        <ul class="scrollbar-thin flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <li v-for="m in data.members" :key="m.id"
                class="group flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <div class="relative shrink-0">
                    <Avatar :name="m.name" :src="m.photo_url" size="sm" />
                    <span class="absolute -bottom-0.5 -right-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-slate-900"
                        :class="dot[m.status]??'bg-slate-300'" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ m.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ m.position }}</p>
                </div>
                <div class="flex gap-1 opacity-0 transition group-hover:opacity-100">
                    <a v-if="m.email" :href="`mailto:${m.email}`"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700">
                        <Mail class="size-4" />
                    </a>
                    <a v-if="m.phone" :href="`tel:${m.phone}`"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700">
                        <Phone class="size-4" />
                    </a>
                </div>
            </li>
        </ul>
    </div>
</template>
