<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { Building2, Plus, Pencil, Power, Trash2, Search, Upload, Users, MapPin } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { useAuthStore } from '@/stores/auth';
import PageHeader from '@/components/ui/PageHeader.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import CompanyForm from '../components/CompanyForm.vue';

const ui = useUiStore();
const auth = useAuthStore();
const companies = ref([]);
const loading = ref(true);
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q ? companies.value.filter((c) => `${c.name} ${c.code} ${c.location ?? ''}`.toLowerCase().includes(q)) : companies.value;
});

async function load() {
    loading.value = true;
    try {
        companies.value = (await api.get('/organization/companies')).data.data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

const editor = reactive({ open: false, id: null, form: {}, errors: {}, saving: false });
function openEditor(c = null) {
    Object.assign(editor, {
        open: true, id: c?.id ?? null, errors: {}, saving: false,
        form: c
            ? { name: c.name, code: c.code, email: c.email, phone: c.phone, location: c.location, address: c.address, tax_pin: c.tax_pin, incorporation_number: c.incorporation_number, incorporation_date: c.incorporation_date, brand_color: c.brand_color }
            : { name: '', code: '', email: '', phone: '', location: '', address: '', tax_pin: '', incorporation_number: '', incorporation_date: '', brand_color: '#4f46e5', admin_name: '', admin_email: '' },
    });
}

async function save() {
    editor.saving = true;
    editor.errors = {};
    const payload = Object.fromEntries(Object.entries(editor.form).map(([k, v]) => [k, v === '' ? null : v]));
    if (payload.code) payload.code = payload.code.toUpperCase();
    try {
        const { data } = editor.id ? await api.put(`/organization/companies/${editor.id}`, payload) : await api.post('/organization/companies', payload);
        ui.toast(data.message);
        editor.open = false;
        await load();
        if (!editor.id) auth.fetchUser(); // refresh the company switcher
    } catch (e) {
        editor.errors = fieldErrors(e);
        if (!Object.keys(editor.errors).length) ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

async function toggle(c) {
    try {
        const { data } = await api.post(`/organization/companies/${c.id}/toggle-active`);
        c.is_active = data.data.is_active;
        ui.toast(data.message);
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

async function uploadLogo(c, e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const fd = new FormData();
    fd.append('logo', file);
    try {
        const { data } = await api.post(`/organization/companies/${c.id}/logo`, fd);
        c.logo_url = data.logo_url;
        ui.toast(data.message);
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    }
}

const del = reactive({ row: null, loading: false, error: '' });
async function remove() {
    del.loading = true;
    del.error = '';
    try {
        const { data } = await api.delete(`/organization/companies/${del.row.id}`);
        ui.toast(data.message);
        del.row = null;
        load();
        auth.fetchUser();
    } catch (e) {
        del.error = errorMessage(e);
    } finally {
        del.loading = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Companies" subtitle="Every company on the platform. Each company's data is kept separate.">
            <button class="btn-primary" @click="openEditor()"><Plus class="size-4" /> New company</button>
        </PageHeader>

        <div class="mb-4 relative max-w-sm">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input v-model="search" class="input !pl-9" placeholder="Search companies…" />
        </div>

        <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><div v-for="i in 3" :key="i" class="card h-48 animate-pulse" /></div>
        <div v-else-if="!filtered.length" class="card">
            <EmptyState :icon="Building2" title="No companies found" description="Create a company to start onboarding its staff." />
        </div>
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="c in filtered" :key="c.id" class="card flex flex-col overflow-hidden" :class="!c.is_active && 'opacity-75'">
                <div class="h-1.5" :style="{ background: c.brand_color }" />
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex items-start gap-4">
                        <label class="group relative flex size-14 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800" title="Upload logo (PNG/JPEG, max 2 MB)">
                            <img v-if="c.logo_url" :src="c.logo_url" :alt="c.name" class="max-h-full max-w-full object-contain" />
                            <Building2 v-else class="size-6 text-slate-300" />
                            <span class="absolute inset-0 hidden items-center justify-center bg-slate-900/50 text-white group-hover:flex"><Upload class="size-4" /></span>
                            <input type="file" accept="image/png,image/jpeg" class="sr-only" @change="uploadLogo(c, $event)" />
                        </label>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ c.name }}</h3>
                            <p class="truncate text-sm text-slate-500">{{ c.location || '—' }}</p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <Badge tone="slate">{{ c.code }}</Badge>
                                <Badge :tone="c.is_active ? 'green' : 'red'">{{ c.is_active ? 'Active' : 'Inactive' }}</Badge>
                            </div>
                        </div>
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center dark:bg-slate-800/50">
                        <div><dt class="text-xs text-slate-500">Staff</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ c.employees_count }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Branches</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ c.branches_count }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Users</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ c.users_count }}</dd></div>
                    </dl>
                    <p class="mt-3 truncate text-xs text-slate-500">{{ [c.email, c.phone].filter(Boolean).join(' · ') || 'No contact details' }}</p>
                    <div class="mt-auto flex items-center justify-end gap-1 pt-4">
                        <button class="btn-ghost !p-2" title="Edit" @click="openEditor(c)"><Pencil class="size-4" /></button>
                        <button class="btn-ghost !p-2" :class="c.is_active ? '!text-amber-600' : '!text-emerald-600'" :title="c.is_active ? 'Deactivate' : 'Activate'" @click="toggle(c)"><Power class="size-4" /></button>
                        <button class="btn-ghost !p-2 !text-rose-600" title="Delete" @click="del.row = c; del.error = ''"><Trash2 class="size-4" /></button>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="editor.open" :title="editor.id ? 'Edit company' : 'New company'" size="lg" @close="editor.open = false">
            <CompanyForm v-model="editor.form" :errors="editor.errors" show-code :show-admin="!editor.id" @submit="save" />
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> {{ editor.id ? 'Save changes' : 'Create company' }}</button>
            </template>
        </Modal>

        <ConfirmDialog
            :open="!!del.row"
            title="Delete company"
            :message="`Delete ${del.row?.name}? Only companies without staff or user accounts can be deleted — deactivate the others instead.`"
            confirm-label="Delete"
            danger
            :loading="del.loading"
            :error="del.error"
            @confirm="remove"
            @close="del.row = null"
        />
    </div>
</template>
