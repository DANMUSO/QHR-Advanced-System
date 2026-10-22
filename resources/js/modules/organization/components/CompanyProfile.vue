<script setup>
import { ref, reactive, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { Building2, Mail, Phone, MapPin, Upload, Trash2, Pencil, Users, Network, Briefcase, UserPlus, FileText, Calendar, Hash } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import { formatDate } from '@/lib/format';
import StatCard from '@/components/ui/StatCard.vue';
import Modal from '@/components/ui/Modal.vue';
import Spinner from '@/components/ui/Spinner.vue';
import Badge from '@/components/ui/Badge.vue';
import CompanyForm from './CompanyForm.vue';

const props = defineProps({ companyId: [String, Number], summary: { type: Object, default: () => ({}) } });
const emit = defineEmits(['quick-add']);
const ui = useUiStore();

const company = ref(null);
const loading = ref(true);
const uploading = ref(false);
const editor = reactive({ open: false, form: {}, errors: {}, saving: false });

async function load() {
    if (!props.companyId) { loading.value = false; return; }
    loading.value = true;
    try {
        company.value = (await api.get(`/organization/companies/${props.companyId}`)).data.data;
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

function edit() {
    const c = company.value;
    Object.assign(editor, {
        open: true, errors: {}, saving: false,
        form: { name: c.name, code: c.code, email: c.email, phone: c.phone, location: c.location, address: c.address, tax_pin: c.tax_pin, incorporation_number: c.incorporation_number, incorporation_date: c.incorporation_date, brand_color: c.brand_color },
    });
}

async function save() {
    editor.saving = true;
    editor.errors = {};
    try {
        const { data } = await api.put(`/organization/companies/${company.value.id}`, editor.form);
        ui.toast(data.message);
        editor.open = false;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
        if (!Object.keys(editor.errors).length) ui.toast(errorMessage(e), 'error');
    } finally {
        editor.saving = false;
    }
}

async function uploadLogo(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) return ui.toast('The logo must not be larger than 2 MB.', 'error');
    uploading.value = true;
    const fd = new FormData();
    fd.append('logo', file);
    try {
        const { data } = await api.post(`/organization/companies/${company.value.id}/logo`, fd);
        company.value.logo_url = data.logo_url;
        ui.toast(data.message);
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    } finally {
        uploading.value = false;
    }
}

async function removeLogo() {
    try {
        const { data } = await api.delete(`/organization/companies/${company.value.id}/logo`);
        company.value.logo_url = null;
        ui.toast(data.message);
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    }
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="grid gap-4 lg:grid-cols-3"><div class="card h-64 animate-pulse lg:col-span-2" /><div class="card h-64 animate-pulse" /></div>
    <div v-else-if="!company" class="card p-10 text-center text-sm text-slate-500">Select a company to see its profile.</div>
    <div v-else class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Active staff" :value="company.employees_count" :icon="Users" />
            <StatCard label="Branches" :value="company.branches_count" :icon="MapPin" tone="sky" />
            <StatCard label="Departments" :value="company.departments_count" :icon="Network" tone="violet" />
            <StatCard label="User accounts" :value="company.users_count" :icon="Briefcase" tone="green" />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card overflow-hidden lg:col-span-2">
                <div class="h-2" :style="{ background: company.brand_color }" />
                <div class="p-6">
                    <div class="flex flex-wrap items-start gap-5">
                        <div class="group relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                            <img v-if="company.logo_url" :src="company.logo_url" :alt="company.name" class="max-h-full max-w-full object-contain" />
                            <Building2 v-else class="size-8 text-slate-300" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ company.name }}</h2>
                                <Badge tone="slate">{{ company.code }}</Badge>
                                <Badge :tone="company.is_active ? 'green' : 'red'">{{ company.is_active ? 'Active' : 'Inactive' }}</Badge>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ company.location || 'Location not set' }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <label class="btn-secondary !py-1.5 cursor-pointer">
                                    <Spinner v-if="uploading" /><Upload v-else class="size-4" /> {{ company.logo_url ? 'Change logo' : 'Upload logo' }}
                                    <input type="file" accept="image/png,image/jpeg" class="sr-only" @change="uploadLogo" />
                                </label>
                                <button v-if="company.logo_url" class="btn-ghost !py-1.5 !text-rose-600" @click="removeLogo"><Trash2 class="size-4" /> Remove</button>
                                <button class="btn-secondary !py-1.5" @click="edit"><Pencil class="size-4" /> Edit profile</button>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">PNG or JPEG, up to 2 MB.</p>
                        </div>
                    </div>

                    <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div class="flex gap-3"><Mail class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Email</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.email || '—' }}</dd></div></div>
                        <div class="flex gap-3"><Phone class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Phone</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.phone || '—' }}</dd></div></div>
                        <div class="flex gap-3"><MapPin class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Address</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.address || '—' }}</dd></div></div>
                        <div class="flex gap-3"><Hash class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Tax PIN</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.tax_pin || '—' }}</dd></div></div>
                        <div class="flex gap-3"><FileText class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Incorporation no.</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.incorporation_number || '—' }}</dd></div></div>
                        <div class="flex gap-3"><Calendar class="mt-0.5 size-4 text-slate-400" /><div><dt class="text-xs text-slate-500">Incorporated</dt><dd class="text-slate-800 dark:text-slate-200">{{ company.incorporation_date ? formatDate(company.incorporation_date) : '—' }}</dd></div></div>
                    </dl>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card p-5">
                    <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Quick actions</h3>
                    <div class="grid gap-2">
                        <button class="btn-secondary justify-start" @click="emit('quick-add', 'branches')"><MapPin class="size-4" /> Add branch</button>
                        <button class="btn-secondary justify-start" @click="emit('quick-add', 'departments')"><Network class="size-4" /> Add department</button>
                        <RouterLink to="/employees/new" class="btn-secondary justify-start"><UserPlus class="size-4" /> Add staff</RouterLink>
                    </div>
                </div>
                <div class="card p-5">
                    <h3 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">HR contact</h3>
                    <template v-if="company.hr_contact?.name || company.hr_contact?.email">
                        <p class="font-medium text-slate-800 dark:text-slate-200">{{ company.hr_contact.name }}</p>
                        <p class="text-sm text-slate-500">{{ company.hr_contact.email }}</p>
                        <p class="text-sm text-slate-500">{{ company.hr_contact.phone }}</p>
                    </template>
                    <p v-else class="text-sm text-slate-500">Not set. Add one under <RouterLink :to="{ path: '/organization/settings', query: { tab: 'company' } }" class="text-brand-600 hover:underline">Settings → Company settings</RouterLink>.</p>
                    <div v-if="company.contacts?.length" class="mt-4 space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <div v-for="c in company.contacts" :key="c.id" class="text-sm">
                            <p class="font-medium text-slate-700 dark:text-slate-300">{{ c.name }} <span v-if="c.role" class="font-normal text-slate-400">· {{ c.role }}</span></p>
                            <p class="text-xs text-slate-500">{{ [c.email, c.phone].filter(Boolean).join(' · ') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="editor.open" title="Edit company profile" size="lg" @close="editor.open = false">
            <CompanyForm v-model="editor.form" :errors="editor.errors" :show-code="company.can_manage_all" @submit="save" />
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> Save changes</button>
            </template>
        </Modal>
    </div>
</template>
