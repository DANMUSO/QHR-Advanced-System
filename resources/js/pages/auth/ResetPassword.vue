<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, reactive, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCircle2, AlertCircle } from 'lucide-vue-next';
import api, { fieldErrors } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import PasswordStrength from '@/components/ui/PasswordStrength.vue';
import Spinner from '@/components/ui/Spinner.vue';

/** Shared by "reset password" and "activate account" — both set a password from an emailed token. */
const props = defineProps({ mode: { type: String, default: 'reset' } });

const route = useRoute();
const router = useRouter();
const ui = useUiStore();
const activating = computed(() => props.mode === 'activate');

const form = reactive({
    token: route.query.token ?? '',
    email: route.query.email ?? '',
    password: '',
    password_confirmation: '',
});
const errors = useFormErrors(() => form);
const loading = ref(false);
const done = ref(false);
const invalidLink = !form.token || !form.email;
const mismatch = computed(() => form.password_confirmation && form.password !== form.password_confirmation);

async function submit() {
    errors.value = {};
    loading.value = true;
    try {
        const { data } = await api.post(activating.value ? '/auth/activate' : '/auth/reset-password', form);
        done.value = true;
        ui.toast(data.message);
        // The server signed out any previous session on this browser; forget it here too, so the next screen is
        // the sign-in for the account just activated, not the old user's dashboard.
        const auth = useAuthStore();
        auth.user = null;
        try { localStorage.removeItem('companyScope'); localStorage.removeItem('companyScopeAll'); } catch { /* storage unavailable */ }
        localStorage.setItem('lastEmail', form.email);
        setTimeout(() => router.push({ path: '/login', query: { email: form.email } }), 2500);
    } catch (e) {
        errors.value = fieldErrors(e);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <div v-if="invalidLink" class="text-center">
            <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-rose-50 text-rose-600 dark:bg-rose-500/15"><AlertCircle class="size-7" /></div>
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Invalid link</h2>
            <p class="mt-2 text-sm text-slate-500">This link is incomplete. Open the link from your email again, or request a new one.</p>
            <RouterLink to="/forgot-password" class="btn-primary mt-6">Request a new link</RouterLink>
        </div>

        <div v-else-if="done" class="text-center">
            <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15"><CheckCircle2 class="size-7" /></div>
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">{{ activating ? 'Account activated' : 'Password reset' }}</h2>
            <p class="mt-2 text-sm text-slate-500">Taking you to sign in…</p>
        </div>

        <template v-else>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ activating ? 'Activate your account' : 'Choose a new password' }}</h2>
            <p class="mt-1.5 text-sm text-slate-500">
                {{ activating ? 'Welcome aboard! Create a password to finish setting up' : 'Set a new password for' }}
                <span class="font-medium text-slate-700 dark:text-slate-300">{{ form.email }}</span>.
            </p>

            <div v-if="errors.email" class="mt-6 flex gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <AlertCircle class="mt-0.5 size-4 shrink-0" /> {{ errors.email }}
            </div>

            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <label class="label" for="password">New password</label>
                    <PasswordInput id="password" v-model="form.password" autocomplete="new-password" :error="errors.password" autofocus />
                    <PasswordStrength :password="form.password" />
                </div>
                <div>
                    <label class="label" for="confirm">Confirm password</label>
                    <PasswordInput id="confirm" v-model="form.password_confirmation" autocomplete="new-password" :error="mismatch ? 'Passwords do not match.' : null" />
                </div>
                <button class="btn-primary w-full py-3" :disabled="loading || !form.password || mismatch">
                    <Spinner v-if="loading" /> {{ activating ? 'Activate account' : 'Reset password' }}
                </button>
            </form>
        </template>
    </div>
</template>
