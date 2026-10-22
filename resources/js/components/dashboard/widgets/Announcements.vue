<script setup>
import { ref } from 'vue';
import { Megaphone, Pin } from 'lucide-vue-next';
import api from '@/lib/api';
import { timeAgo } from '@/lib/format';
import WidgetHeader from '../WidgetHeader.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';

const props = defineProps({ data: Object, title: String });
const selected = ref(null);
const unread   = ref(props.data.unread);

async function open(a) {
    selected.value = a;
    if (!a.is_read) {
        a.is_read = true;
        unread.value = Math.max(0, unread.value - 1);
        await api.post(`/announcements/${a.id}/read`);
    }
}
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="Megaphone"
            icon-class="bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400"
            :subtitle="unread ? `${unread} unread` : 'All read'" to="/announcements" />
        <EmptyState v-if="!data.items.length" :icon="Megaphone" title="No announcements" />
        <ul v-else class="flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
            <li v-for="a in data.items" :key="a.id">
                <button class="flex w-full items-start gap-3 px-5 py-3.5 text-left hover:bg-slate-50 dark:hover:bg-slate-800/40"
                    @click="open(a)">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full transition"
                        :class="a.is_read ? 'bg-slate-200 dark:bg-slate-700' : 'bg-brand-500'" />
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-slate-100">
                            <Pin v-if="a.pinned" class="size-3.5 shrink-0 text-amber-500" />
                            {{ a.title }}
                        </p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ a.excerpt }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">{{ a.author ?? 'HR' }} · {{ timeAgo(a.published_at) }}</p>
                    </div>
                </button>
            </li>
        </ul>
        <Modal :open="!!selected" :title="selected?.title" @close="selected = null">
            <p class="mb-4 text-xs text-slate-500">{{ selected?.author ?? 'HR' }} · {{ timeAgo(selected?.published_at) }}</p>
            <p class="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ selected?.body }}</p>
        </Modal>
    </div>
</template>
