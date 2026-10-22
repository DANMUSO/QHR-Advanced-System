<script setup>
import { ref } from 'vue';
import { Copy, Download, ShieldCheck } from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import OtpInput from '@/components/ui/OtpInput.vue';
import Spinner from '@/components/ui/Spinner.vue';

const emit = defineEmits(['enabled']);
const ui = useUiStore();

const stage = ref('intro'); // intro | scan | codes
const qr = ref('');
const secret = ref('');
const code = ref('');
const error = ref('');
const loading = ref(false);
const recoveryCodes = ref([]);

async function start() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.post('/auth/two-factor');
        qr.value = data.qr_svg;
        secret.value = data.secret;
        stage.value = 'scan';
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function confirm() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.post('/auth/two-factor/confirm', { code: code.value });
        recoveryCodes.value = data.recovery_codes;
        stage.value = 'codes';
    } catch (e) {
        error.value = errorMessage(e);
        code.value = '';
    } finally {
        loading.value = false;
    }
}

function copy(text) {
    navigator.clipboard?.writeText(text);
    ui.toast('Copied to clipboard');
}

function downloadCodes() {
    const blob = new Blob([`HR Advanced recovery codes\nKeep these somewhere safe. Each code works once.\n\n${recoveryCodes.value.join('\n')}\n`], { type: 'text/plain' });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: 'hr-advanced-recovery-codes.txt' });
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>

<template>
    <div>
        <div v-if="stage === 'intro'">
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Add a second step to sign-in using an authenticator app such as Google Authenticator, Microsoft Authenticator or Authy.
            </p>
            <p v-if="error" class="field-error">{{ error }}</p>
            <button class="btn-primary mt-4" :disabled="loading" @click="start"><Spinner v-if="loading" /> Set up two-factor</button>
        </div>

        <div v-else-if="stage === 'scan'" class="space-y-5">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-400">
                <li>Open your authenticator app and scan this QR code.</li>
                <li>Enter the 6-digit code it shows to confirm.</li>
            </ol>
            <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                <div class="rounded-2xl border border-slate-200 bg-white p-3" v-html="qr" />
                <div class="w-full text-sm">
                    <p class="text-slate-500">Can't scan? Enter this key manually:</p>
                    <div class="mt-2 flex items-center gap-2">
                        <code class="flex-1 break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs dark:bg-slate-800">{{ secret }}</code>
                        <button class="btn-ghost !p-2" @click="copy(secret)" title="Copy key"><Copy class="size-4" /></button>
                    </div>
                </div>
            </div>
            <form class="space-y-3" @submit.prevent="confirm">
                <OtpInput v-model="code" :error="!!error" @complete="confirm" />
                <p v-if="error" class="field-error text-center">{{ error }}</p>
                <div class="flex justify-center"><button class="btn-primary" :disabled="loading || code.length < 6"><Spinner v-if="loading" /> Confirm & enable</button></div>
            </form>
        </div>

        <div v-else class="space-y-4">
            <div class="flex items-center gap-2 text-emerald-600"><ShieldCheck class="size-5" /><span class="font-semibold">Two-factor authentication is on</span></div>
            <p class="text-sm text-slate-600 dark:text-slate-400">Save these recovery codes. If you lose your phone, each code lets you sign in once.</p>
            <div class="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-4 font-mono text-sm dark:bg-slate-800">
                <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button class="btn-secondary" @click="copy(recoveryCodes.join('\n'))"><Copy class="size-4" /> Copy</button>
                <button class="btn-secondary" @click="downloadCodes"><Download class="size-4" /> Download</button>
                <button class="btn-primary" @click="emit('enabled')">I've saved my codes</button>
            </div>
        </div>
    </div>
</template>
