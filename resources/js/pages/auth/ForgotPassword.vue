<script setup>
import { ref } from 'vue';
import { Mail, ArrowLeft, MailCheck } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import Spinner from '@/components/ui/Spinner.vue';

const email = ref(localStorage.getItem('lastEmail') || '');
const sent = ref(false);
const error = ref('');
const loading = ref(false);

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        await api.post('/auth/forgot-password', { email: email.value });
        sent.value = true;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <RouterLink to="/login" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
            <ArrowLeft class="size-4" /> Back to sign in
        </RouterLink>

        <template v-if="!sent">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Forgot your password?</h2>
            <p class="mt-1.5 text-sm text-slate-500">Enter your work email and we'll send you a secure reset link.</p>
            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="email" class="label">Work email</label>
                    <div class="relative">
                        <Mail class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input id="email" v-model.trim="email" type="email" required autofocus class="input pl-10" placeholder="you@company.co.ke" />
                    </div>
                    <p v-if="error" class="field-error">{{ error }}</p>
                </div>
                <button class="btn-primary w-full py-3" :disabled="loading || !email">
                    <Spinner v-if="loading" /> Send reset link
                </button>
            </form>
        </template>

        <div v-else class="text-center">
            <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15">
                <MailCheck class="size-7" />
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Check your inbox</h2>
            <p class="mt-2 text-sm text-slate-500">
                If an account exists for <span class="font-medium text-slate-700 dark:text-slate-300">{{ email }}</span>, a reset link is on its way. It expires in 60 minutes.
            </p>
            <button class="btn-secondary mt-6" @click="sent = false">Use a different email</button>
        </div>
    </div>
</template>
