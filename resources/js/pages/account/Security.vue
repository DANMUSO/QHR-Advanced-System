<script setup>
import { ref, onMounted } from 'vue';
import { KeyRound, ShieldCheck, ShieldOff, MonitorSmartphone, History, Laptop, Smartphone, RefreshCw, CheckCircle2, XCircle, Lock, LogOut, AlertTriangle } from 'lucide-vue-next';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { formatDateTime, timeAgo } from '@/lib/format';
import ChangePasswordForm from '@/components/account/ChangePasswordForm.vue';
import TwoFactorSetup from '@/components/account/TwoFactorSetup.vue';
import Modal from '@/components/ui/Modal.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

const auth = useAuthStore();
const ui = useUiStore();

const sessions = ref([]);
const history = ref({ data: [], current_page: 1, last_page: 1 });
const passwordModal = ref(false);

// Password-confirmed actions share one modal.
const confirm = ref({ open: false, action: null, title: '', password: '', error: '', loading: false });
const recoveryCodes = ref([]);

async function loadSessions() {
    sessions.value = (await api.get('/account/sessions')).data.data;
}
async function loadHistory(page = 1) {
    history.value = (await api.get('/account/login-history', { params: { page } })).data;
}

function askPassword(action, title) {
    confirm.value = { open: true, action, title, password: '', error: '', loading: false };
}

async function runConfirmed() {
    const c = confirm.value;
    c.loading = true;
    c.error = '';
    try {
        if (c.action === 'disable2fa') {
            await api.delete('/auth/two-factor', { data: { current_password: c.password } });
            ui.toast('Two-factor authentication disabled.', 'warning');
            await auth.fetchUser();
        } else if (c.action === 'recovery') {
            const { data } = await api.post('/auth/two-factor/recovery-codes', { current_password: c.password });
            recoveryCodes.value = data.recovery_codes;
        } else if (c.action === 'logoutOthers') {
            const { data } = await api.post('/account/sessions/logout-others', { current_password: c.password });
            ui.toast(data.message);
            await loadSessions();
        }
        c.open = false;
    } catch (e) {
        c.error = fieldErrors(e).current_password ?? errorMessage(e);
    } finally {
        c.loading = false;
    }
}

async function revoke(s) {
    await api.delete(`/account/sessions/${s.id}`);
    ui.toast('Session signed out.');
    loadSessions();
}

async function onTwoFactorEnabled() {
    ui.toast('Two-factor authentication enabled.');
    await auth.fetchUser();
}

const eventMeta = {
    login: { label: 'Signed in', tone: 'green', icon: CheckCircle2 },
    logout: { label: 'Signed out', tone: 'slate', icon: LogOut },
    failed: { label: 'Failed attempt', tone: 'red', icon: XCircle },
    locked: { label: 'Blocked (locked)', tone: 'red', icon: Lock },
    two_factor_failed: { label: '2FA failed', tone: 'amber', icon: AlertTriangle },
};

onMounted(() => {
    loadSessions();
    loadHistory();
});
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Account security</h1>
            <p class="mt-1 text-sm text-slate-500">Manage your password, two-factor authentication and signed-in devices.</p>
        </div>

        <!-- Password -->
        <section class="card p-6">
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-brand-50 p-2.5 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400"><KeyRound class="size-5" /></div>
                <div class="flex-1">
                    <h2 class="font-semibold text-slate-900 dark:text-white">Password</h2>
                    <p class="text-sm text-slate-500">
                        Last changed {{ auth.user?.security.password_changed_at ? timeAgo(auth.user.security.password_changed_at) : 'never' }}.
                        Changing it signs out your other devices.
                    </p>
                </div>
                <button class="btn-secondary" @click="passwordModal = true"><KeyRound class="size-4" /> Change password</button>
            </div>
        </section>

        <!-- Two-factor -->
        <section class="card p-6">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="rounded-xl p-2.5" :class="auth.user?.security.two_factor_enabled ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/15'">
                        <component :is="auth.user?.security.two_factor_enabled ? ShieldCheck : ShieldOff" class="size-5" />
                    </div>
                    <div>
                        <h2 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                            Two-factor authentication
                            <Badge :tone="auth.user?.security.two_factor_enabled ? 'green' : 'amber'">{{ auth.user?.security.two_factor_enabled ? 'On' : 'Off' }}</Badge>
                            <Badge v-if="auth.user?.security.two_factor_required" tone="brand">Required for your role</Badge>
                        </h2>
                        <p class="text-sm text-slate-500">Require a code from your phone in addition to your password.</p>
                    </div>
                </div>
            </div>
            <div v-if="auth.user?.security.two_factor_enabled" class="flex flex-wrap gap-2">
                <button class="btn-secondary" @click="askPassword('recovery', 'Generate new recovery codes')"><RefreshCw class="size-4" /> New recovery codes</button>
                <button v-if="!auth.user?.security.two_factor_required" class="btn-secondary !text-rose-600" @click="askPassword('disable2fa', 'Turn off two-factor authentication')">
                    <ShieldOff class="size-4" /> Turn off
                </button>
            </div>
            <TwoFactorSetup v-else @enabled="onTwoFactorEnabled" />
            <div v-if="recoveryCodes.length" class="mt-4 rounded-xl bg-slate-100 p-4 dark:bg-slate-800">
                <p class="mb-2 text-sm font-medium">Your new recovery codes (old ones no longer work):</p>
                <div class="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4"><span v-for="c in recoveryCodes" :key="c">{{ c }}</span></div>
            </div>
        </section>

        <!-- Sessions -->
        <section class="card p-6">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="rounded-xl bg-sky-50 p-2.5 text-sky-600 dark:bg-sky-500/15"><MonitorSmartphone class="size-5" /></div>
                    <div>
                        <h2 class="font-semibold text-slate-900 dark:text-white">Active sessions</h2>
                        <p class="text-sm text-slate-500">Devices currently signed in to your account.</p>
                    </div>
                </div>
                <button v-if="sessions.length > 1" class="btn-secondary" @click="askPassword('logoutOthers', 'Sign out all other sessions')">Sign out others</button>
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="s in sessions" :key="s.id" class="flex items-center gap-4 py-3">
                    <component :is="s.device === 'Mobile' ? Smartphone : Laptop" class="size-6 text-slate-400" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                            {{ s.browser }} on {{ s.platform }}
                            <Badge v-if="s.is_current" tone="green" class="ml-1">This device</Badge>
                        </p>
                        <p class="text-xs text-slate-500">{{ s.ip_address }} · active {{ timeAgo(s.last_active) }}</p>
                    </div>
                    <button v-if="!s.is_current" class="btn-ghost text-xs" @click="revoke(s)">Sign out</button>
                </li>
            </ul>
        </section>

        <!-- History -->
        <section class="card p-6">
            <div class="mb-5 flex items-start gap-3">
                <div class="rounded-xl bg-violet-50 p-2.5 text-violet-600 dark:bg-violet-500/15"><History class="size-5" /></div>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">Sign-in history</h2>
                    <p class="text-sm text-slate-500">Recent sign-in activity on your account. Report anything you don't recognise to HR.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="pb-2 pr-4">Event</th><th class="pb-2 pr-4">Device</th><th class="pb-2 pr-4">IP address</th><th class="pb-2">When</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="h in history.data" :key="h.id">
                            <td class="py-2.5 pr-4">
                                <Badge :tone="eventMeta[h.event]?.tone">
                                    <component :is="eventMeta[h.event]?.icon" class="size-3" /> {{ eventMeta[h.event]?.label ?? h.event }}
                                </Badge>
                                <Badge v-if="h.new_device" tone="amber" class="ml-1">New device</Badge>
                            </td>
                            <td class="py-2.5 pr-4 text-slate-600 dark:text-slate-400">{{ h.browser }} · {{ h.platform }}</td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-slate-500">{{ h.ip_address }}</td>
                            <td class="py-2.5 text-slate-500">{{ formatDateTime(h.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="history.last_page > 1" class="mt-4 flex justify-end gap-2">
                <button class="btn-secondary !py-1.5" :disabled="history.current_page <= 1" @click="loadHistory(history.current_page - 1)">Previous</button>
                <button class="btn-secondary !py-1.5" :disabled="history.current_page >= history.last_page" @click="loadHistory(history.current_page + 1)">Next</button>
            </div>
        </section>

        <Modal :open="passwordModal" title="Change password" size="sm" @close="passwordModal = false">
            <ChangePasswordForm @changed="(m) => { ui.toast(m); loadSessions(); passwordModal = false; }" />
        </Modal>

        <Modal :open="confirm.open" :title="confirm.title" size="sm" @close="confirm.open = false">
            <p class="mb-4 text-sm text-slate-500">For your security, confirm your password to continue.</p>
            <form @submit.prevent="runConfirmed">
                <PasswordInput v-model="confirm.password" :error="confirm.error" autofocus />
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="confirm.open = false">Cancel</button>
                    <button class="btn-primary" :disabled="confirm.loading || !confirm.password"><Spinner v-if="confirm.loading" /> Confirm</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
