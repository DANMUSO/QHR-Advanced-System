<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    ArrowLeft, ShieldCheck, ShieldAlert, Lock, Unlock, UserX, UserCheck, MailQuestion, KeyRound, LogIn, Check, Save, RotateCcw, MailWarning, Link2,
} from 'lucide-vue-next';
import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';
import { copyActivationLink } from '@/lib/activation';
import { formatDateTime, timeAgo, titleCase } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Spinner from '@/components/ui/Spinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const STATUS = { active: ['green', 'Active'], disabled: ['red', 'Disabled'], locked: ['amber', 'Locked'], not_activated: ['orange', 'Not activated'] };

const loading = ref(true);
const notFound = ref('');
const payload = ref(null);
const user = computed(() => payload.value?.data ?? null);
const roles = ref([]);
const direct = ref([]);
const savingRoles = ref(false);
const savingPerms = ref(false);
const busy = ref('');

async function load() {
    loading.value = true;
    notFound.value = '';
    try {
        const { data } = await api.get(`/admin/users/${route.params.id}`);
        payload.value = data;
        roles.value = [...data.data.roles];
        direct.value = [...data.data.direct_permissions];
    } catch (e) {
        payload.value = null;
        notFound.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

const rolesDirty = computed(() => user.value && [...roles.value].sort().join() !== [...user.value.roles].sort().join());
const permsDirty = computed(() => user.value && [...direct.value].sort().join() !== [...user.value.direct_permissions].sort().join());
const grantable = computed(() => new Set(payload.value?.grantable_permissions ?? []));
const fromRoles = computed(() => user.value?.role_permissions ?? {});

function toggleRole(name) {
    roles.value = roles.value.includes(name) ? roles.value.filter((r) => r !== name) : [...roles.value, name];
}
function toggleDirect(perm) {
    if (!grantable.value.has(perm)) return;
    direct.value = direct.value.includes(perm) ? direct.value.filter((p) => p !== perm) : [...direct.value, perm];
}

async function saveRoles() {
    savingRoles.value = true;
    try {
        const { data } = await api.put(`/admin/users/${user.value.id}/roles`, { roles: roles.value });
        swal.toast(data.message);
        await load();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        savingRoles.value = false;
    }
}

async function savePermissions() {
    savingPerms.value = true;
    try {
        const { data } = await api.put(`/admin/users/${user.value.id}/permissions`, { permissions: direct.value });
        swal.toast(data.message);
        await load();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        savingPerms.value = false;
    }
}

const actions = computed(() => {
    const u = user.value;
    if (!u || u.is_self) return [];
    const list = [];
    if (u.status === 'disabled') list.push({ key: 'enable', label: 'Enable account', icon: UserCheck, cls: 'btn-secondary' });
    else list.push({ key: 'disable', label: 'Disable account', icon: UserX, cls: 'btn-danger', confirm: `Disable ${u.name}? They will be signed out immediately.` });
    if (u.status === 'locked') list.push({ key: 'unlock', label: 'Unlock', icon: Unlock, cls: 'btn-secondary' });
    if (u.status === 'not_activated') list.push({ key: 'resend-activation', label: 'Resend activation email', icon: MailQuestion, cls: 'btn-primary' });
    if (u.two_factor_enabled) list.push({ key: 'reset-two-factor', label: 'Reset 2FA', icon: ShieldAlert, cls: 'btn-secondary', confirm: `Reset two-factor authentication for ${u.name}?` });
    list.push(u.must_change_password
        ? { key: 'force-password-change', label: 'Clear forced password change', icon: KeyRound, cls: 'btn-secondary', body: { value: false } }
        : { key: 'force-password-change', label: 'Force password change', icon: KeyRound, cls: 'btn-secondary' });
    return list;
});

async function runAction(a) {
    if (a.confirm && !(await swal.confirm({ title: a.label, text: a.confirm, confirmLabel: a.label, danger: a.cls === 'btn-danger' }))) return;
    busy.value = a.key;
    try {
        const { data } = await api.post(`/admin/users/${user.value.id}/${a.key}`, a.body ?? {});
        swal.toast(data.message);
        await load();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        busy.value = '';
    }
}

async function copyLink() {
    busy.value = 'activation-link';
    await copyActivationLink(user.value);
    busy.value = '';
}

async function impersonate() {
    if (!(await swal.confirm({ title: 'Sign in as this user?', text: `You will see the app as ${user.value.name}. Every action is recorded.`, confirmLabel: 'Sign in as user', danger: false }))) return;
    busy.value = 'impersonate';
    try {
        await api.post(`/admin/impersonate/${user.value.id}`);
        window.location.href = router.resolve('/').href;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
        busy.value = '';
    }
}

watch(() => route.params.id, (id) => { if (id) load(); }, { immediate: true });
</script>

<template>
    <div>
        <RouterLink to="/organization/users" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">
            <ArrowLeft class="size-4" /> Users &amp; roles
        </RouterLink>

        <div v-if="loading && !user" class="space-y-4">
            <div class="card h-28 animate-pulse" /><div class="card h-64 animate-pulse" />
        </div>

        <EmptyState v-else-if="!user" title="User not found" :description="notFound || 'This account does not exist or is outside your company.'" />

        <template v-else>
            <PageHeader :title="user.name" :subtitle="user.email">
                <button v-if="payload.can_impersonate" class="btn-secondary" :disabled="busy === 'impersonate'" @click="impersonate">
                    <Spinner v-if="busy === 'impersonate'" /><LogIn v-else class="size-4" /> Sign in as
                </button>
            </PageHeader>

            <div v-if="user.status === 'not_activated' && !user.is_self" class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                <MailWarning class="size-5 shrink-0" />
                <p class="flex-1"><strong>Not activated.</strong> {{ user.name }} cannot sign in until they open the activation email and set a password.</p>
                <button class="btn-primary !py-1.5 text-xs" :disabled="!!busy" @click="runAction(actions.find((a) => a.key === 'resend-activation'))"><Spinner v-if="busy === 'resend-activation'" /> Resend activation email</button>
                <button v-if="payload.can_copy_activation_link" class="btn-secondary !py-1.5 text-xs" :disabled="!!busy" title="Local development only" @click="copyLink"><Spinner v-if="busy === 'activation-link'" /><Link2 v-else class="size-3.5" /> Copy activation link</button>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- Summary + status actions -->
                <div class="space-y-5">
                    <div class="card p-5">
                        <div class="flex items-center gap-3">
                            <Avatar :name="user.name" :src="user.employee?.photo_url" size="lg" />
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900 dark:text-white">{{ user.name }}</p>
                                <p class="truncate text-sm text-slate-500">{{ user.email }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    <Badge :tone="STATUS[user.status]?.[0]">{{ STATUS[user.status]?.[1] ?? user.status }}</Badge>
                                    <Badge v-if="user.must_change_password" tone="amber"><KeyRound class="size-3" /> Must change password</Badge>
                                </div>
                            </div>
                        </div>
                        <dl class="mt-5 space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Company</dt><dd class="text-right text-slate-800 dark:text-slate-200">{{ user.company?.name ?? 'All companies' }}</dd></div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">Employee record</dt>
                                <dd class="text-right">
                                    <RouterLink v-if="user.employee" :to="`/employees/${user.employee.id}`" class="text-brand-600 hover:underline dark:text-brand-400">{{ user.employee.employee_number }}</RouterLink>
                                    <span v-else class="text-slate-400">Not linked</span>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">Two-factor</dt>
                                <dd class="flex items-center gap-1" :class="user.two_factor_enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                                    <ShieldCheck v-if="user.two_factor_enabled" class="size-4" /><ShieldAlert v-else class="size-4" /> {{ user.two_factor_enabled ? 'Enabled' : 'Off' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Activated</dt><dd class="text-slate-800 dark:text-slate-200">{{ user.activated_at ? formatDateTime(user.activated_at) : 'Not yet' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Last sign-in</dt><dd class="text-slate-800 dark:text-slate-200" :title="user.last_login_at ? formatDateTime(user.last_login_at) : ''">{{ user.last_login_at ? timeAgo(user.last_login_at) : 'Never' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Password changed</dt><dd class="text-slate-800 dark:text-slate-200">{{ user.password_changed_at ? timeAgo(user.password_changed_at) : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Failed sign-ins</dt><dd class="text-slate-800 dark:text-slate-200">{{ user.failed_login_attempts ?? 0 }}</dd></div>
                            <div v-if="user.locked_until" class="flex justify-between gap-3"><dt class="text-slate-500">Locked until</dt><dd class="text-amber-600">{{ formatDateTime(user.locked_until) }}</dd></div>
                        </dl>
                    </div>

                    <div class="card p-5">
                        <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">Account actions</h3>
                        <p v-if="user.is_self" class="text-sm text-slate-500">This is your own account. Manage it from <RouterLink to="/account/security" class="text-brand-600 hover:underline">Account security</RouterLink>.</p>
                        <div v-else class="flex flex-col gap-2">
                            <button v-for="a in actions" :key="a.key + a.label" :class="a.cls" class="justify-start" :disabled="!!busy" @click="runAction(a)">
                                <Spinner v-if="busy === a.key" /><component :is="a.icon" v-else class="size-4" /> {{ a.label }}
                            </button>
                        </div>
                    </div>

                    <div class="card p-5">
                        <h3 class="mb-3 font-semibold text-slate-900 dark:text-white">Recent sign-ins</h3>
                        <ul v-if="payload.login_history?.length" class="space-y-2 text-sm">
                            <li v-for="l in payload.login_history" :key="l.id" class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-slate-700 dark:text-slate-300">{{ l.ip_address ?? '—' }}<span v-if="l.browser && l.browser !== 'Unknown'" class="text-xs text-slate-400"> · {{ l.browser }}</span></span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <Badge :tone="/fail|lock|block/i.test(l.event ?? '') ? 'red' : 'green'">{{ titleCase(l.event ?? 'login') }}</Badge>
                                    <span class="text-xs text-slate-500">{{ timeAgo(l.created_at) }}</span>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-slate-400">No sign-ins recorded.</p>
                    </div>
                </div>

                <!-- Roles + permissions -->
                <div class="space-y-5 lg:col-span-2">
                    <div class="card p-5">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">Roles</h3>
                                <p class="text-xs text-slate-500">Roles grant a standard set of permissions.</p>
                            </div>
                            <div v-if="!user.is_self" class="flex gap-2">
                                <button v-if="rolesDirty" class="btn-ghost" @click="roles = [...user.roles]"><RotateCcw class="size-4" /> Reset</button>
                                <button class="btn-primary" :disabled="!rolesDirty || savingRoles" @click="saveRoles"><Spinner v-if="savingRoles" /><Save v-else class="size-4" /> Save roles</button>
                            </div>
                        </div>
                        <p v-if="user.is_self" class="mb-3 text-sm text-amber-600 dark:text-amber-400">You cannot change your own roles.</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label v-for="r in payload.assignable_roles" :key="r.name"
                                class="flex items-center gap-3 rounded-xl border p-3 text-sm transition"
                                :class="[roles.includes(r.name) ? 'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-900/20' : 'border-slate-200 dark:border-slate-700', user.is_self ? 'cursor-not-allowed opacity-70' : 'cursor-pointer']">
                                <input type="checkbox" class="rounded text-brand-600" :checked="roles.includes(r.name)" :disabled="user.is_self" @change="toggleRole(r.name)" />
                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ r.label }}</span>
                                <code class="ml-auto text-xs text-slate-400">{{ r.name }}</code>
                            </label>
                        </div>
                    </div>

                    <div class="card p-5">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">Permissions</h3>
                                <p class="text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1"><Check class="size-3 text-emerald-500" /> from a role</span> ·
                                    tick <strong>Direct</strong> to grant extra access to this person only.
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button v-if="permsDirty" class="btn-ghost" @click="direct = [...user.direct_permissions]"><RotateCcw class="size-4" /> Reset</button>
                                <button class="btn-primary" :disabled="!permsDirty || savingPerms" @click="savePermissions"><Spinner v-if="savingPerms" /><Save v-else class="size-4" /> Save permissions</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-700">
                                        <th class="py-2 pr-3 font-semibold">Permission</th>
                                        <th class="px-3 py-2 font-semibold">From roles</th>
                                        <th class="px-3 py-2 text-center font-semibold">Direct</th>
                                        <th class="py-2 pl-3 text-center font-semibold">Effective</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(perms, group) in payload.catalogue" :key="group">
                                        <tr><td colspan="4" class="pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ group }}</td></tr>
                                        <tr v-for="(label, perm) in perms" :key="perm" class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-2 pr-3">
                                                <p class="text-slate-800 dark:text-slate-200">{{ label }}</p>
                                                <code class="text-xs text-slate-400">{{ perm }}</code>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex flex-wrap gap-1">
                                                    <Badge v-for="r in fromRoles[perm] ?? []" :key="r" tone="slate"><Check class="size-3 text-emerald-500" /> {{ titleCase(r) }}</Badge>
                                                    <span v-if="!(fromRoles[perm] ?? []).length" class="text-xs text-slate-300 dark:text-slate-600">—</span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" class="rounded text-brand-600 disabled:opacity-40" :checked="direct.includes(perm)" :disabled="!grantable.has(perm)"
                                                    :title="grantable.has(perm) ? '' : 'You cannot grant this permission'" @change="toggleDirect(perm)" />
                                            </td>
                                            <td class="py-2 pl-3 text-center">
                                                <Check v-if="(fromRoles[perm] ?? []).length || direct.includes(perm)" class="mx-auto size-4 text-emerald-500" />
                                                <span v-else class="text-slate-300 dark:text-slate-600">—</span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
