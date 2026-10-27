<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Users, ShieldCheck, Lock, UserX, MailQuestion, Search, KeyRound, ShieldAlert, Pencil, Check, Send, Link2, MailWarning, UserPlus } from 'lucide-vue-next';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import swal from '@/lib/swal';
import { resendActivation, copyActivationLink } from '@/lib/activation';
import { timeAgo, formatDateTime, titleCase } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Badge from '@/components/ui/Badge.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import PermissionMatrix from '../components/PermissionMatrix.vue';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();

const tab = ref(route.query.tab === 'roles' ? 'roles' : 'users');
const tabs = [{ key: 'users', label: 'Users', icon: Users }, { key: 'roles', label: 'Roles & permissions', icon: ShieldCheck }];
function switchTab(t) {
    tab.value = t;
    router.replace({ query: { tab: t } });
    if (t === 'roles' && !roles.value.length) loadRoles();
}

// ---- users ------------------------------------------------------------------
const STATUS = { active: ['green', 'Active'], disabled: ['red', 'Disabled'], locked: ['amber', 'Locked'], not_activated: ['orange', 'Not activated'] };
const filters = reactive({ search: '', role: '', status: STATUS[route.query.status] ? route.query.status : '', company_id: '', page: 1, sort: 'name', dir: 'asc' });
const result = ref({ data: [], stats: {}, roles: [] });
const loading = ref(false);

const columns = [
    { key: 'name', label: 'User', sortable: true },
    { key: 'roles', label: 'Roles', format: (v) => (v ?? []).join(', ') },
    { key: 'two_factor_enabled', label: '2FA', align: 'center', format: (v) => (v ? 'On' : 'Off') },
    { key: 'last_login_at', label: 'Last sign-in', sortable: true, format: (v) => (v ? formatDateTime(v) : 'Never') },
    { key: 'status', label: 'Status', format: (v) => STATUS[v]?.[1] ?? v },
];
const columnsWithCompany = computed(() => (auth.isSuperAdmin ? [...columns.slice(0, 1), { key: 'company.name', label: 'Company' }, ...columns.slice(1)] : columns));
const paginator = computed(() => (result.value.data ? { current_page: result.value.current_page, last_page: result.value.last_page, total: result.value.total, from: result.value.from, to: result.value.to } : null));

let timer;
async function loadUsers() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null));
        result.value = (await api.get('/admin/users', { params })).data;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}
watch(() => [filters.role, filters.status, filters.company_id], () => { filters.page = 1; loadUsers(); });
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => { filters.page = 1; loadUsers(); }, 300); });
function onSort({ key, dir }) { filters.sort = key; filters.dir = dir; loadUsers(); }
function onPage(p) { filters.page = p; loadUsers(); }
function setStatus(s) { filters.status = filters.status === s ? '' : s; }

const busy = ref(null);
async function resend(row) {
    busy.value = row.id;
    await resendActivation(row);
    busy.value = null;
}
async function copyLink(row) {
    busy.value = row.id;
    await copyActivationLink(row);
    busy.value = null;
}

// ---- roles ------------------------------------------------------------------
const roles = ref([]);
const catalogue = ref({});
const canEditRoles = ref(false);
const rolesLoading = ref(false);
const roleEditor = reactive({ open: false, role: null, permissions: [], saving: false });

async function loadRoles() {
    rolesLoading.value = true;
    try {
        const { data } = await api.get('/admin/roles');
        roles.value = data.data;
        catalogue.value = data.catalogue;
        canEditRoles.value = data.can_edit;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        rolesLoading.value = false;
    }
}

const permissionLabels = computed(() => Object.assign({}, ...Object.values(catalogue.value)));
function groupedFor(role) {
    return Object.entries(catalogue.value)
        .map(([group, perms]) => [group, Object.keys(perms).filter((p) => role.permissions.includes(p))])
        .filter(([, list]) => list.length);
}

function editRole(role) {
    Object.assign(roleEditor, { open: true, role, permissions: [...role.permissions], saving: false });
}

async function saveRole() {
    roleEditor.saving = true;
    try {
        const { data } = await api.put(`/admin/roles/${roleEditor.role.id}`, { permissions: roleEditor.permissions });
        swal.toast(data.message);
        roleEditor.open = false;
        loadRoles();
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
    } finally {
        roleEditor.saving = false;
    }
}

// ---- standalone user (super admin): a login without an employee record, e.g. an external accountant ----
const creator = reactive({ open: false, form: {}, errors: {}, saving: false });
function openCreate() {
    Object.assign(creator, { open: true, errors: {}, saving: false, form: { name: '', email: '', company_id: ui.companyScope || '', roles: [], send_activation: true } });
}
function toggleCreateRole(r) {
    const i = creator.form.roles.indexOf(r);
    if (i === -1) creator.form.roles.push(r);
    else creator.form.roles.splice(i, 1);
}
async function createUser() {
    creator.saving = true;
    creator.errors = {};
    try {
        const { data } = await api.post('/admin/users', { ...creator.form, company_id: creator.form.company_id || null });
        swal.toast(data.message);
        creator.open = false;
        router.push(`/organization/users/${data.data.id}`);
    } catch (e) {
        creator.errors = fieldErrors(e);
        if (!Object.keys(creator.errors).length) swal.toast(errorMessage(e), 'error');
    } finally {
        creator.saving = false;
    }
}

onMounted(() => { loadUsers(); if (tab.value === 'roles') loadRoles(); });
</script>

<template>
    <div>
        <PageHeader title="Users & roles" subtitle="Accounts, sign-in security, roles and permissions.">
            <button v-if="result.can_create_users" class="btn-primary" @click="openCreate"><UserPlus class="size-4" /> New user</button>
        </PageHeader>
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />

        <template v-if="tab === 'users'">
            <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <button class="text-left" @click="filters.status = ''"><StatCard label="All accounts" :value="result.stats.total ?? '—'" :icon="Users" :hint="`${result.stats.two_factor ?? 0} with 2FA`" /></button>
                <button class="text-left" @click="setStatus('active')"><StatCard label="Active" :value="result.stats.active ?? '—'" :icon="ShieldCheck" tone="green" /></button>
                <button class="text-left" @click="setStatus('locked')"><StatCard label="Locked" :value="result.stats.locked ?? '—'" :icon="Lock" tone="amber" /></button>
                <button class="text-left" @click="setStatus('not_activated')"><StatCard label="Not activated" :value="result.stats.not_activated ?? '—'" :icon="MailQuestion" tone="violet" /></button>
                <button class="text-left" @click="setStatus('disabled')"><StatCard label="Disabled" :value="result.stats.disabled ?? '—'" :icon="UserX" tone="red" /></button>
            </div>

            <div v-if="result.stats.not_activated && filters.status !== 'not_activated'" class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                <MailWarning class="size-5 shrink-0" />
                <p class="flex-1">
                    <strong>{{ result.stats.not_activated }} account{{ result.stats.not_activated === 1 ? ' has' : 's have' }} not been activated.</strong>
                    These people cannot sign in until they open their activation email and set a password.
                </p>
                <button class="btn-secondary !py-1.5 text-xs" @click="setStatus('not_activated')">Review and resend</button>
            </div>

            <DataTable
                :columns="columnsWithCompany"
                :rows="result.data"
                :loading="loading"
                :paginator="paginator"
                export-name="users"
                clickable
                empty-title="No users match"
                empty-description="Try a different search or filter."
                @row-click="(r) => router.push(`/organization/users/${r.id}`)"
                @sort="onSort"
                @page="onPage"
            >
                <template #toolbar>
                    <div class="relative w-full sm:w-64">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input v-model="filters.search" class="input !py-2 !pl-9" placeholder="Search name or email…" />
                    </div>
                    <select v-model="filters.role" class="input !w-44 !py-2">
                        <option value="">All roles</option>
                        <option v-for="r in result.roles" :key="r" :value="r">{{ titleCase(r) }}</option>
                    </select>
                    <select v-model="filters.status" class="input !w-40 !py-2">
                        <option value="">Any status</option>
                        <option v-for="(s, k) in STATUS" :key="k" :value="k">{{ s[1] }}</option>
                    </select>
                    <select v-if="auth.isSuperAdmin && !ui.companyScope" v-model="filters.company_id" class="input !w-52 !py-2">
                        <option value="">All companies</option>
                        <option v-for="c in auth.user.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </template>
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-3">
                        <Avatar :name="row.name" :src="row.employee?.photo_url" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900 dark:text-white">{{ row.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ row.email }}<span v-if="!row.employee" class="ml-1 text-slate-400">· no employee record</span></p>
                        </div>
                    </div>
                </template>
                <template #cell-roles="{ value }">
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="r in value" :key="r" :tone="r === 'super-admin' ? 'red' : r === 'hr-admin' ? 'brand' : 'slate'">{{ titleCase(r) }}</Badge>
                        <span v-if="!value.length" class="text-xs text-slate-400">No role</span>
                    </div>
                </template>
                <template #cell-two_factor_enabled="{ value }">
                    <ShieldCheck v-if="value" class="mx-auto size-4 text-emerald-500" /><ShieldAlert v-else class="mx-auto size-4 text-slate-300 dark:text-slate-600" />
                </template>
                <template #cell-last_login_at="{ value }">
                    <span v-if="value" :title="formatDateTime(value)">{{ timeAgo(value) }}</span><span v-else class="text-slate-400">Never</span>
                </template>
                <template #cell-status="{ row, value }">
                    <Badge :tone="STATUS[value]?.[0]">{{ STATUS[value]?.[1] ?? value }}</Badge>
                    <KeyRound v-if="row.must_change_password" class="ml-1 inline size-3.5 text-amber-500" title="Must change password" />
                </template>
                <template #actions="{ row }">
                    <template v-if="row.status === 'not_activated' && row.can_send_activation !== false">
                        <button class="btn-secondary !px-2.5 !py-1.5 text-xs" :disabled="busy === row.id" title="Email a new activation link" @click="resend(row)"><Send class="size-3.5" /> Resend activation</button>
                        <button v-if="result.can_copy_activation_link" class="btn-ghost !px-2.5 !py-1.5 text-xs" :disabled="busy === row.id" title="Local development only" @click="copyLink(row)"><Link2 class="size-3.5" /> Copy link</button>
                    </template>
                    <RouterLink :to="`/organization/users/${row.id}`" class="btn-ghost !px-2.5 !py-1.5 text-xs">Manage</RouterLink>
                </template>
            </DataTable>
        </template>

        <template v-else>
            <div v-if="rolesLoading" class="grid gap-4 lg:grid-cols-2"><div v-for="i in 4" :key="i" class="card h-44 animate-pulse" /></div>
            <template v-else>
                <p class="mb-4 text-sm text-slate-500">
                    Roles are permission templates. Give a person extra access from their user page instead of changing a role, unless the change should apply to everyone in it.
                    <span v-if="!canEditRoles">Only a super admin can edit role permissions.</span>
                </p>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div v-for="role in roles" :key="role.id" class="card p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">{{ role.label }}</h3>
                                <p class="text-xs text-slate-500"><code>{{ role.name }}</code> · {{ role.users_count }} user{{ role.users_count === 1 ? '' : 's' }} · {{ role.permissions.length }} permissions</p>
                            </div>
                            <button v-if="canEditRoles && !role.is_super" class="btn-secondary !px-3 !py-1.5" @click="editRole(role)"><Pencil class="size-3.5" /> Edit</button>
                            <Badge v-else-if="role.is_super" tone="red">All permissions</Badge>
                        </div>
                        <div v-if="!role.is_super" class="mt-4 space-y-2">
                            <div v-for="[group, list] in groupedFor(role)" :key="group">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ group }}</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="p in list" :key="p" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300" :title="p"><Check class="size-3 text-emerald-500" /> {{ permissionLabels[p] ?? p }}</span>
                                </div>
                            </div>
                            <p v-if="!role.permissions.length" class="text-sm text-slate-400">No permissions.</p>
                        </div>
                    </div>
                </div>
            </template>
        </template>

        <Modal :open="roleEditor.open" :title="`Edit role: ${roleEditor.role?.label}`" size="xl" @close="roleEditor.open = false">
            <p class="mb-4 text-sm text-slate-500">Changes apply immediately to all {{ roleEditor.role?.users_count }} user(s) with this role and are recorded in the audit trail.</p>
            <PermissionMatrix v-model="roleEditor.permissions" :catalogue="catalogue" />
            <template #footer>
                <button class="btn-secondary" @click="roleEditor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="roleEditor.saving" @click="saveRole"><Spinner v-if="roleEditor.saving" /> Save role</button>
            </template>
        </Modal>

        <Modal :open="creator.open" title="New user without an employee record" size="lg" @close="creator.open = false">
            <p class="mb-4 text-sm text-slate-500">For people who sign in only to approve or review, such as an external accountant or a director. Staff accounts are created from the employee record instead.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="nu-name">Full name <span class="text-rose-500">*</span></label>
                    <input id="nu-name" v-model="creator.form.name" class="input" maxlength="120" />
                    <p v-if="creator.errors.name" class="field-error">{{ creator.errors.name }}</p>
                </div>
                <div>
                    <label class="label" for="nu-email">Email <span class="text-rose-500">*</span></label>
                    <input id="nu-email" v-model="creator.form.email" type="email" class="input" maxlength="190" />
                    <p v-if="creator.errors.email" class="field-error">{{ creator.errors.email }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="nu-company">Company</label>
                    <select id="nu-company" v-model="creator.form.company_id" class="input">
                        <option value="">None (platform-wide)</option>
                        <option v-for="c in auth.user?.companies ?? []" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Approvers such as Finance act on the company chosen here.</p>
                    <p v-if="creator.errors.company_id" class="field-error">{{ creator.errors.company_id }}</p>
                </div>
                <div class="sm:col-span-2">
                    <p class="label">Roles <span class="text-rose-500">*</span></p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="r in result.roles" :key="r" class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 p-2.5 text-sm dark:border-slate-700">
                            <input type="checkbox" class="rounded text-brand-600" :checked="creator.form.roles.includes(r)" @change="toggleCreateRole(r)" />
                            {{ titleCase(r) }}
                        </label>
                    </div>
                    <p v-if="creator.errors.roles" class="field-error">{{ creator.errors.roles }}</p>
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input v-model="creator.form.send_activation" type="checkbox" class="rounded text-brand-600" /> Email an activation link now
                </label>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="creator.open = false">Cancel</button>
                <button class="btn-primary" :disabled="creator.saving" @click="createUser"><Spinner v-if="creator.saving" /> Create user</button>
            </template>
        </Modal>
    </div>
</template>
