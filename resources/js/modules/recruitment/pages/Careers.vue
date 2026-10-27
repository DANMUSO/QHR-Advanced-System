<script setup>
import { useFormErrors } from '@/lib/formErrors';
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Briefcase, MapPin, CalendarClock, ArrowLeft, Search, Upload, CheckCircle2 } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import Spinner from '@/components/ui/Spinner.vue';

// Public careers page (no login): /careers?company=<code> lists open jobs, /careers/<slug> shows one and takes applications.
const route = useRoute();
const router = useRouter();

const postings = ref([]);
const company = ref(null);
const posting = ref(null);
const loading = ref(true);
const notFound = ref(false);
const search = ref('');

const form = ref({ name: '', email: '', phone: '', cover_letter: '', website: '' });
const cv = ref(null);
const errors = useFormErrors(() => form.value);
const submitting = ref(false);
const submitted = ref('');
const formError = ref('');

const slug = computed(() => route.params.slug || null);

async function loadList() {
    loading.value = true; notFound.value = false;
    try {
        const { data } = await api.get('/careers', { params: { company: route.query.company || undefined, search: search.value || undefined } });
        postings.value = data.data ?? [];
        company.value = data.company;
    } catch { notFound.value = true; } finally { loading.value = false; }
}

async function loadPosting() {
    loading.value = true; notFound.value = false; submitted.value = '';
    try {
        posting.value = (await api.get(`/careers/postings/${slug.value}`)).data.data;
    } catch { notFound.value = true; posting.value = null; } finally { loading.value = false; }
}

async function apply() {
    submitting.value = true; errors.value = {}; formError.value = '';
    try {
        const fd = new FormData();
        Object.entries(form.value).forEach(([k, v]) => v && fd.append(k, v));
        if (cv.value) fd.append('cv', cv.value);
        const { data } = await api.post(`/careers/postings/${slug.value}/apply`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        submitted.value = data.message;
    } catch (e) {
        errors.value = fieldErrors(e);
        formError.value = e?.response?.status === 429 ? 'Too many applications from this connection. Please try again later.' : errorMessage(e);
    } finally { submitting.value = false; }
}

function load() { slug.value ? loadPosting() : loadList(); }
watch(slug, load);
onMounted(load);
</script>

<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex max-w-4xl items-center gap-3 px-4 py-4">
                <img v-if="(posting?.company ?? company)?.logo_url" :src="(posting?.company ?? company).logo_url" alt="" class="size-10 rounded-lg object-contain" />
                <div v-else class="flex size-10 items-center justify-center rounded-lg bg-brand-600 text-white"><Briefcase class="size-5" /></div>
                <div>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ (posting?.company ?? company)?.name ?? 'Careers' }}</p>
                    <p class="text-xs text-slate-500">Open positions</p>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>

            <div v-else-if="notFound" class="card p-10 text-center text-slate-500">
                This job is no longer open.
                <button class="btn-secondary mt-4" @click="router.push({ path: '/careers', query: route.query })">See open positions</button>
            </div>

            <!-- One posting -->
            <template v-else-if="slug && posting">
                <button class="btn-ghost mb-4 !px-2" @click="router.push({ path: '/careers', query: posting.company ? { company: posting.company.code } : {} })"><ArrowLeft class="size-4" /> All jobs</button>
                <div class="card p-6">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ posting.title }}</h1>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm text-slate-500">
                        <span v-if="posting.department" class="flex items-center gap-1"><Briefcase class="size-4" /> {{ posting.department }}</span>
                        <span v-if="posting.location" class="flex items-center gap-1"><MapPin class="size-4" /> {{ posting.location }}</span>
                        <span v-if="posting.closing_date" class="flex items-center gap-1"><CalendarClock class="size-4" /> Closes {{ formatDate(posting.closing_date) }}</span>
                    </div>
                    <div v-if="posting.description" class="mt-6">
                        <h2 class="font-semibold text-slate-900 dark:text-white">About the role</h2>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ posting.description }}</p>
                    </div>
                    <div v-if="posting.requirements" class="mt-6">
                        <h2 class="font-semibold text-slate-900 dark:text-white">Requirements</h2>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ posting.requirements }}</p>
                    </div>
                </div>

                <div class="card mt-6 p-6">
                    <div v-if="submitted" class="flex flex-col items-center py-6 text-center">
                        <CheckCircle2 class="size-10 text-green-500" />
                        <p class="mt-3 font-semibold text-slate-900 dark:text-white">{{ submitted }}</p>
                        <p class="text-sm text-slate-500">We will contact you if you are shortlisted.</p>
                    </div>
                    <form novalidate v-else class="space-y-4" @submit.prevent="apply">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Apply for this job</h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div><label class="label">Full name *</label><input v-model="form.name" class="input" required maxlength="150" /><p v-if="errors.name" class="field-error">{{ errors.name }}</p></div>
                            <div><label class="label">Email *</label><input v-model="form.email" type="email" class="input" required /><p v-if="errors.email" class="field-error">{{ errors.email }}</p></div>
                            <div><label class="label">Phone</label><input v-model="form.phone" type="tel" class="input" placeholder="07XXXXXXXX" /><p v-if="errors.phone" class="field-error">{{ errors.phone }}</p></div>
                            <div>
                                <label class="label">CV (PDF or Word, max 5 MB) *</label>
                                <label class="input flex cursor-pointer items-center gap-2 text-sm text-slate-500">
                                    <Upload class="size-4" /><span class="truncate">{{ cv?.name ?? 'Choose file…' }}</span>
                                    <input type="file" class="hidden" accept=".pdf,.doc,.docx" @change="cv = $event.target.files[0] ?? null" />
                                </label>
                                <p v-if="errors.cv" class="field-error">{{ errors.cv }}</p>
                            </div>
                        </div>
                        <div><label class="label">Cover letter</label><textarea v-model="form.cover_letter" rows="5" class="input" maxlength="5000" /></div>
                        <!-- Honeypot: hidden from people, filled by bots -->
                        <input v-model="form.website" type="text" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" />
                        <p v-if="formError" class="field-error">{{ formError }}</p>
                        <div class="flex justify-end">
                            <button type="submit" class="btn-primary" :disabled="submitting || !cv"><Spinner v-if="submitting" /> Submit application</button>
                        </div>
                    </form>
                </div>
            </template>

            <!-- List -->
            <template v-else>
                <div class="relative mb-6">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" class="input !pl-9" placeholder="Search jobs or locations…" @keyup.enter="loadList" />
                </div>
                <div v-if="!postings.length" class="card p-10 text-center text-slate-500">There are no open positions right now. Please check back later.</div>
                <div v-else class="space-y-3">
                    <RouterLink v-for="p in postings" :key="p.slug" :to="{ path: `/careers/${p.slug}`, query: route.query }" class="card block p-5 transition hover:border-brand-300 dark:hover:border-brand-500/40">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold text-slate-900 dark:text-white">{{ p.title }}</p>
                                <p class="text-sm text-slate-500">{{ [p.company?.name, p.department, p.location].filter(Boolean).join(' · ') }}</p>
                            </div>
                            <span v-if="p.closing_date" class="text-xs text-slate-400">Closes {{ formatDate(p.closing_date) }}</span>
                        </div>
                        <p v-if="p.summary" class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ p.summary }}</p>
                    </RouterLink>
                </div>
            </template>
        </main>
    </div>
</template>
