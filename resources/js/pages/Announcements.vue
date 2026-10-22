<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Megaphone, Plus, Pin, Pencil, Trash2, Eye } from 'lucide-vue-next';
import api, { fieldErrors, errorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { formatDateTime, timeAgo } from '@/lib/format';
import Modal from '@/components/ui/Modal.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

const auth = useAuthStore();
const ui = useUiStore();
const canManage = auth.can('announcements.manage');

const items = ref([]);
const loading = ref(true);
const editor = reactive({ open: false, id: null, title: '', body: '', pinned: false, published_at: '', expires_at: '', all_companies: false, errors: {}, saving: false });

async function load() {
    loading.value = true;
    items.value = (await api.get('/announcements')).data.data;
    loading.value = false;
}

function openEditor(a = null) {
    Object.assign(editor, {
        open: true, id: a?.id ?? null, title: a?.title ?? '', body: a?.body ?? '', pinned: a?.pinned ?? false,
        published_at: a?.published_at?.slice(0, 16) ?? '', expires_at: a?.expires_at?.slice(0, 16) ?? '', all_companies: false, errors: {}, saving: false,
    });
}

async function save() {
    editor.saving = true;
    editor.errors = {};
    const payload = { title: editor.title, body: editor.body, pinned: editor.pinned, published_at: editor.published_at || null, expires_at: editor.expires_at || null, all_companies: editor.all_companies };
    try {
        editor.id ? await api.put(`/announcements/${editor.id}`, payload) : await api.post('/announcements', payload);
        ui.toast(editor.id ? 'Announcement updated.' : 'Announcement published.');
        editor.open = false;
        load();
    } catch (e) {
        editor.errors = fieldErrors(e);
    } finally {
        editor.saving = false;
    }
}

async function remove(a) {
    if (!confirm(`Delete "${a.title}"?`)) return;
    try {
        await api.delete(`/announcements/${a.id}`);
        ui.toast('Announcement deleted.');
        load();
    } catch (e) {
        ui.toast(errorMessage(e), 'error');
    }
}

async function markRead(a) {
    if (a.is_read) return;
    a.is_read = true;
    await api.post(`/announcements/${a.id}/read`);
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Announcements</h1>
                <p class="text-sm text-slate-500">Company news and updates.</p>
            </div>
            <button v-if="canManage" class="btn-primary" @click="openEditor()"><Plus class="size-4" /> New announcement</button>
        </div>

        <div v-if="loading" class="space-y-4"><div v-for="i in 3" :key="i" class="card h-32 animate-pulse" /></div>
        <div v-else-if="!items.length" class="card"><EmptyState :icon="Megaphone" title="No announcements yet" description="Published announcements will appear here." /></div>

        <div v-else class="space-y-4">
            <article v-for="a in items" :id="a.id" :key="a.id" class="card p-5" @mouseenter="markRead(a)">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="flex flex-wrap items-center gap-2 font-semibold text-slate-900 dark:text-white">
                            <Pin v-if="a.pinned" class="size-4 text-amber-500" /> {{ a.title }}
                            <Badge v-if="!a.is_read" tone="brand">New</Badge>
                            <Badge v-if="canManage && new Date(a.published_at) > new Date()" tone="amber">Scheduled</Badge>
                            <Badge v-if="canManage && a.company_id === null" tone="sky">All companies</Badge>
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500" :title="formatDateTime(a.published_at)">{{ a.author?.name ?? 'HR' }} · {{ timeAgo(a.published_at) }}</p>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                        <span class="mr-2 flex items-center gap-1 text-xs text-slate-400" title="Read by"><Eye class="size-3.5" /> {{ a.readers_count }}</span>
                        <button class="btn-ghost !p-1.5" @click="openEditor(a)"><Pencil class="size-4" /></button>
                        <button class="btn-ghost !p-1.5 !text-rose-600" @click="remove(a)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ a.body }}</p>
            </article>
        </div>

        <Modal :open="editor.open" :title="editor.id ? 'Edit announcement' : 'New announcement'" size="lg" @close="editor.open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div>
                    <label class="label">Title</label>
                    <input v-model="editor.title" class="input" maxlength="200" />
                    <p v-if="editor.errors.title" class="field-error">{{ editor.errors.title }}</p>
                </div>
                <div>
                    <label class="label">Message</label>
                    <textarea v-model="editor.body" rows="6" class="input" />
                    <p v-if="editor.errors.body" class="field-error">{{ editor.errors.body }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Publish at <span class="font-normal text-slate-400">(blank = now)</span></label>
                        <input v-model="editor.published_at" type="datetime-local" class="input" />
                    </div>
                    <div>
                        <label class="label">Expires at <span class="font-normal text-slate-400">(optional)</span></label>
                        <input v-model="editor.expires_at" type="datetime-local" class="input" />
                        <p v-if="editor.errors.expires_at" class="field-error">{{ editor.errors.expires_at }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm"><input v-model="editor.pinned" type="checkbox" class="rounded text-brand-600" /> Pin to top</label>
                    <label v-if="auth.isSuperAdmin && !editor.id" class="flex items-center gap-2 text-sm"><input v-model="editor.all_companies" type="checkbox" class="rounded text-brand-600" /> Send to all companies</label>
                </div>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editor.open = false">Cancel</button>
                <button class="btn-primary" :disabled="editor.saving" @click="save"><Spinner v-if="editor.saving" /> {{ editor.id ? 'Save changes' : 'Publish' }}</button>
            </template>
        </Modal>
    </div>
</template>
