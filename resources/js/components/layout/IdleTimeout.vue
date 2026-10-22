<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useIdle } from '@vueuse/core';
import { TimerReset } from 'lucide-vue-next';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import Modal from '@/components/ui/Modal.vue';

/** Warns 60 seconds before the company's idle timeout, then signs the user out. */
const auth = useAuthStore();
const router = useRouter();
const WARNING_SECONDS = 60;

const timeoutMs = computed(() => (auth.user?.security.idle_timeout_minutes ?? 30) * 60 * 1000);
const { idle, reset } = useIdle(timeoutMs.value - WARNING_SECONDS * 1000);
const countdown = ref(WARNING_SECONDS);
let timer;

watch(idle, (isIdle) => {
    clearInterval(timer);
    if (!isIdle) return;
    countdown.value = WARNING_SECONDS;
    timer = setInterval(async () => {
        countdown.value--;
        if (countdown.value <= 0) {
            clearInterval(timer);
            await auth.logout();
            router.replace({ name: 'login', query: { expired: 1 } });
        }
    }, 1000);
});

async function stayActive() {
    clearInterval(timer);
    reset();
    await api.get('/notifications/unread-count').catch(() => {}); // keeps the server session alive
}

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Modal :open="idle && !!auth.user" :dismissible="false" size="sm">
        <div class="text-center">
            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/15"><TimerReset class="size-7" /></div>
            <h2 class="text-lg font-semibold">Are you still there?</h2>
            <p class="mt-1 text-sm text-slate-500">For your security you'll be signed out in <strong class="text-slate-800 dark:text-slate-200">{{ countdown }}s</strong> due to inactivity.</p>
            <div class="mt-6 flex justify-center gap-2">
                <button class="btn-secondary" @click="auth.logout().then(() => router.replace('/login'))">Sign out</button>
                <button class="btn-primary" @click="stayActive">Stay signed in</button>
            </div>
        </div>
    </Modal>
</template>
