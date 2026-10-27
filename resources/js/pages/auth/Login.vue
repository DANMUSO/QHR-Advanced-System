<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, reactive, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Mail, AlertCircle, ArrowLeft, KeyRound, Smartphone, LockKeyhole } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { fieldErrors, errorMessage } from '@/lib/api';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import OtpInput from '@/components/ui/OtpInput.vue';
import Spinner from '@/components/ui/Spinner.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const step = ref('credentials');
const form = reactive({ email: localStorage.getItem('lastEmail') || '', password: '', remember: true });
const errors = useFormErrors(() => form);
function takeNotice() {
    try {
        const text = sessionStorage.getItem('authNotice');
        sessionStorage.removeItem('authNotice');
        return text;
    } catch { return null; }
}
const notice = takeNotice();
const alert = ref(notice ? { tone: 'amber', text: notice } : route.query.expired ? { tone: 'amber', text: 'Your session expired. Please sign in again.' } : null);
const loading = ref(false);

const useRecovery = ref(false);
const code = ref('');
const recoveryCode = ref('');

const alertClass = computed(() => ({
    amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    red: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
}[alert.value?.tone ?? 'red']));

function goNext() {
    router.replace(route.query.redirect || '/');
}

async function submitCredentials() {
    errors.value = {};
    alert.value = null;
    loading.value = true;
    try {
        const { twoFactor } = await auth.login(form);
        localStorage.setItem('lastEmail', form.email);
        if (twoFactor) {
            step.value = 'two-factor';
        } else {
            goNext();
        }
    } catch (e) {
        const status = e.response?.status;
        const fe = fieldErrors(e);
        if (status === 423 || status === 429 || status === 403) {
            alert.value = { tone: status === 423 ? 'red' : 'amber', text: fe.email ?? errorMessage(e) };
        } else if (fe.email && !fe.password) {
            alert.value = { tone: 'red', text: fe.email };
        } else {
            errors.value = fe;
        }
        form.password = '';
    } finally {
        loading.value = false;
    }
}

async function submitTwoFactor() {
    errors.value = {};
    loading.value = true;
    try {
        await auth.twoFactorChallenge(useRecovery.value ? { recovery_code: recoveryCode.value } : { code: code.value });
        goNext();
    } catch (e) {
        if (e.response?.status === 419) {
            step.value = 'credentials';
            alert.value = { tone: 'amber', text: errorMessage(e) };
        } else {
            errors.value = { code: errorMessage(e) };
            code.value = '';
        }
    } finally {
        loading.value = false;
    }
}

function backToCredentials() {
    step.value = 'credentials';
    code.value = '';
    recoveryCode.value = '';
    errors.value = {};
}
</script>

<template>
    <div>
        <!-- Step 1: credentials -->
        <div v-if="step === 'credentials'" key="cred">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Welcome back</h2>
            <p class="mt-1.5 text-sm text-slate-500">Sign in to your HR account to continue.</p>

            <div v-if="alert" class="mt-6 flex gap-3 rounded-xl border p-3.5 text-sm" :class="alertClass" role="alert">
                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                <span>{{ alert.text }}</span>
            </div>

            <form class="mt-6 space-y-5" @submit.prevent="submitCredentials" novalidate>
                <div>
                    <label for="email" class="label">Work email</label>
                    <div class="relative">
                        <Mail class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input id="email" v-model.trim="form.email" type="email" autocomplete="username" required autofocus class="input pl-10" :class="errors.email && 'border-rose-400'" placeholder="you@company.co.ke" />
                    </div>
                    <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
                </div>

                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="password" class="text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                        <RouterLink to="/forgot-password" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Forgot password?</RouterLink>
                    </div>
                    <PasswordInput id="password" v-model="form.password" :error="errors.password" required placeholder="••••••••••" />
                </div>

                <label class="flex items-center gap-2.5 text-sm text-slate-600 dark:text-slate-400">
                    <input v-model="form.remember" type="checkbox" class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                    Keep me signed in on this device
                </label>

                <button type="submit" class="btn-primary w-full py-3" :disabled="loading || !form.email || !form.password">
                    <Spinner v-if="loading" />
                    {{ loading ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <div class="mt-8 flex items-center gap-2 rounded-xl bg-slate-100 p-3 text-xs text-slate-500 dark:bg-slate-900">
                <LockKeyhole class="size-4 shrink-0" />
                Protected by account lockout, two-factor authentication and sign-in alerts.
            </div>
        </div>

        <!-- Step 2: two-factor -->
        <div v-else key="2fa">
            <button class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300" @click="backToCredentials">
                <ArrowLeft class="size-4" /> Back
            </button>
            <div class="mb-5 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                <component :is="useRecovery ? KeyRound : Smartphone" class="size-6" />
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Two-factor verification</h2>
            <p class="mt-1.5 text-sm text-slate-500">
                {{ useRecovery ? 'Enter one of your saved recovery codes.' : 'Enter the 6-digit code from your authenticator app.' }}
            </p>

            <form class="mt-8 space-y-6" @submit.prevent="submitTwoFactor">
                <div v-if="!useRecovery">
                    <OtpInput v-model="code" :error="!!errors.code" autofocus @complete="submitTwoFactor" />
                </div>
                <div v-else>
                    <input v-model.trim="recoveryCode" class="input text-center font-mono tracking-widest uppercase" placeholder="XXXXX-XXXXX" autofocus />
                </div>
                <p v-if="errors.code" class="field-error text-center">{{ errors.code }}</p>

                <button type="submit" class="btn-primary w-full py-3" :disabled="loading || (useRecovery ? !recoveryCode : code.length < 6)">
                    <Spinner v-if="loading" /> Verify
                </button>
                <button type="button" class="w-full text-center text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400" @click="useRecovery = !useRecovery; errors = {}">
                    {{ useRecovery ? 'Use authenticator code instead' : 'Lost your device? Use a recovery code' }}
                </button>
            </form>
        </div>
    </div>
</template>
