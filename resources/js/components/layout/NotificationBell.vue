<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { onClickOutside, useDocumentVisibility } from '@vueuse/core';
import { Bell, CheckCheck, BellOff } from 'lucide-vue-next';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { timeAgo } from '@/lib/format';
import EmptyState from '@/components/ui/EmptyState.vue';

const auth = useAuthStore();
const router = useRouter();
const open = ref(false);
const root = ref(null);
const items = ref([]);
const unread = ref(auth.user?.unread_notifications ?? 0);
const loading = ref(false);
const visibility = useDocumentVisibility();
let timer;

onClickOutside(root, () => (open.value = false));

async function poll() {
    if (visibility.value !== 'visible') return;
    try {
        // Background poll: must not count as activity for the server-side idle timeout.
        unread.value = (await api.get('/notifications/unread-count', { headers: { 'X-Background-Request': '1' } })).data.unread;
    } catch { /* offline or signed out */ }
}

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        loading.value = true;
        const { data } = await api.get('/notifications');
        items.value = data.data;
        unread.value = data.unread;
        loading.value = false;
    }
}

async function openItem(n) {
    if (!n.read_at) {
        unread.value = (await api.post(`/notifications/${n.id}/read`)).data.unread;
        n.read_at = new Date().toISOString();
    }
    open.value = false;
    if (n.url) router.push(n.url);
}

async function markAll() {
    await api.post('/notifications/read-all');
    unread.value = 0;
    items.value.forEach((n) => (n.read_at ??= new Date().toISOString()));
}

onMounted(() => (timer = setInterval(poll, 30000)));
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div ref="root" class="relative">
        <button class="btn-ghost relative !p-2" aria-label="Notifications" @click="toggle">
            <Bell class="size-5" />
            <span v-if="unread" class="absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold leading-4 text-white">
                {{ unread > 9 ? '9+' : unread }}
            </span>
        </button>
        <Transition name="slide-up">
            <div v-if="open" class="card absolute right-0 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden shadow-lg">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="font-semibold">Notifications</p>
                    <button v-if="unread" class="flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700" @click="markAll">
                        <CheckCheck class="size-4" /> Mark all read
                    </button>
                </div>
                <div class="scrollbar-thin max-h-96 overflow-y-auto">
                    <div v-if="loading" class="space-y-3 p-4">
                        <div v-for="i in 3" :key="i" class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" />
                    </div>
                    <EmptyState v-else-if="!items.length" :icon="BellOff" title="You're all caught up" description="New approvals, alerts and updates will appear here." />
                    <button
                        v-for="n in items"
                        v-else
                        :key="n.id"
                        class="flex w-full gap-3 border-b border-slate-50 px-4 py-3 text-left hover:bg-slate-50 dark:border-slate-800/60 dark:hover:bg-slate-800/50"
                        @click="openItem(n)"
                    >
                        <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="n.read_at ? 'bg-transparent' : 'bg-brand-500'" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ n.title }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ n.message }}</span>
                            <span class="mt-0.5 block text-[11px] text-slate-400">{{ timeAgo(n.created_at) }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>
