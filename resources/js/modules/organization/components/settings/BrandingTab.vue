<script setup>
import { ref, onMounted } from 'vue';
import { Upload, Trash2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useUiStore } from '@/stores/ui';
import Spinner from '@/components/ui/Spinner.vue';
import { useCompanyContext } from '../../company';

/** Logo, brand colour and localisation of the current company. */
const ui = useUiStore();
const { cfg } = useCompanyContext();

const form = ref(null);
const options = ref({ timezones: [], date_formats: [] });
const errors = ref({});
const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const currencies = ['KES', 'UGX', 'TZS', 'RWF', 'USD', 'EUR', 'GBP'];

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/organization/branding', cfg());
        form.value = data.data ?? null;
        options.value = data.data?.options ?? options.value;
    } catch (e) {
        form.value = null;
        ui.toast(errorMessage(e), 'error');
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { brand_color, timezone, currency, date_format, fy_start } = form.value;
        const { data } = await api.put('/organization/branding', { brand_color, timezone, currency, date_format, fy_start }, cfg());
        ui.toast(data.message);
    } catch (e) {
        errors.value = fieldErrors(e);
        ui.toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
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
        const { data } = await api.post('/organization/branding/logo', fd, cfg());
        form.value.logo_url = data.logo_url;
        ui.toast(data.message);
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    } finally {
        uploading.value = false;
    }
}

async function removeLogo() {
    try {
        const { data } = await api.delete('/organization/branding/logo', cfg());
        form.value.logo_url = null;
        ui.toast(data.message);
    } catch (err) {
        ui.toast(errorMessage(err), 'error');
    }
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16"><Spinner /></div>
    <div v-else-if="!form" class="card p-10 text-center text-sm text-slate-500">Select a company to edit its branding.</div>
    <div v-else class="mx-auto max-w-2xl space-y-5">
        <section class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Company logo</h3>
            <div class="flex flex-wrap items-center gap-5">
                <div class="flex size-20 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                    <img v-if="form.logo_url" :src="form.logo_url" alt="Company logo" class="max-h-full max-w-full object-contain" />
                    <span v-else class="text-xs text-slate-400">No logo</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="btn-secondary cursor-pointer"><Spinner v-if="uploading" /><Upload v-else class="size-4" /> Upload logo
                        <input type="file" accept="image/png,image/jpeg" class="sr-only" @change="uploadLogo" />
                    </label>
                    <button v-if="form.logo_url" class="btn-ghost !text-rose-600" @click="removeLogo"><Trash2 class="size-4" /> Remove</button>
                </div>
                <p class="w-full text-xs text-slate-400">PNG or JPEG, up to 2 MB.</p>
            </div>
        </section>
        <section class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Brand colour</h3>
            <div class="flex items-center gap-3">
                <input v-model="form.brand_color" type="color" class="size-10 cursor-pointer rounded border border-slate-200 dark:border-slate-700" aria-label="Brand colour picker" />
                <input v-model="form.brand_color" class="input w-32" placeholder="#4f46e5" aria-label="Brand colour" />
            </div>
            <p v-if="errors.brand_color" class="field-error">{{ errors.brand_color }}</p>
        </section>
        <section class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Localisation</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Timezone</label>
                    <select v-model="form.timezone" class="input"><option v-for="tz in options.timezones" :key="tz" :value="tz">{{ tz }}</option></select>
                    <p v-if="errors.timezone" class="field-error">{{ errors.timezone }}</p>
                </div>
                <div>
                    <label class="label">Currency</label>
                    <select v-model="form.currency" class="input"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select>
                </div>
                <div>
                    <label class="label">Date format</label>
                    <select v-model="form.date_format" class="input"><option v-for="f in options.date_formats" :key="f" :value="f">{{ f }}</option></select>
                </div>
                <div>
                    <label class="label">Financial year starts (DD-MM)</label>
                    <input v-model="form.fy_start" class="input" placeholder="01-07" />
                    <p v-if="errors.fy_start" class="field-error">{{ errors.fy_start }}</p>
                </div>
            </div>
        </section>
        <div class="flex justify-end">
            <button class="btn-primary" :disabled="saving" @click="save"><Spinner v-if="saving" /> Save branding</button>
        </div>
    </div>
</template>
