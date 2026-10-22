<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { UserCheck } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { errorMessage } from '@/lib/api';

const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();
const busy = ref(false);

async function stop() {
    busy.value = true;
    try {
        await auth.stopImpersonating();
        // Full reload (as when impersonation starts): a client-side route change kept the impersonated user's
        // dashboard, greeting and cached stores on screen until the next refresh.
        window.location.href = router.resolve('/').href;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="auth.user?.impersonator" class="flex items-center justify-center gap-3 bg-amber-400 px-4 py-2 text-sm font-semibold text-amber-950">
        <UserCheck class="size-4" />
        <span>You are signed in as <strong>{{ auth.user.name }}</strong> (impersonated by {{ auth.user.impersonator.name }}). Actions are logged.</span>
        <button class="rounded-lg bg-amber-950/90 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-950" :disabled="busy" @click="stop">Return to my account</button>
    </div>
</template>
