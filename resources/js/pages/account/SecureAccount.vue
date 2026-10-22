<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { KeyRound, ShieldCheck, LogOut } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import ChangePasswordForm from '@/components/account/ChangePasswordForm.vue';
import TwoFactorSetup from '@/components/account/TwoFactorSetup.vue';

/** Shown after sign-in when a temporary password must be changed or the role requires 2FA. */
const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();

const step = computed(() => (auth.user?.security.must_change_password ? 'password' : 'two-factor'));

async function refresh(message) {
    if (message) ui.toast(message);
    auth.securityBlock = null;
    await auth.fetchUser();
    if (!auth.needsSecuritySetup) router.replace('/');
}

async function logout() {
    await auth.logout();
    router.replace('/login');
}
</script>

<template>
    <div>
        <div class="mb-5 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
            <component :is="step === 'password' ? KeyRound : ShieldCheck" class="size-6" />
        </div>
        <template v-if="step === 'password'">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Set your own password</h2>
            <p class="mt-1.5 mb-6 text-sm text-slate-500">You signed in with a temporary password. Choose a new one to continue.</p>
            <ChangePasswordForm @changed="refresh" />
        </template>
        <template v-else>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Turn on two-factor authentication</h2>
            <p class="mt-1.5 mb-6 text-sm text-slate-500">Your role has access to sensitive data, so your organisation requires a second sign-in step.</p>
            <TwoFactorSetup @enabled="refresh('Two-factor authentication enabled.')" />
        </template>
        <button class="mt-8 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700" @click="logout">
            <LogOut class="size-4" /> Sign out
        </button>
    </div>
</template>
