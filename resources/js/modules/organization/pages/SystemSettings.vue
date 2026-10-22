<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { SlidersHorizontal, Palette, FileSpreadsheet, Workflow, BellRing, Plug, Webhook, DatabaseBackup } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import CompanyPicker from '../components/CompanyPicker.vue';
import CompanySettingsTab from '../components/settings/CompanySettingsTab.vue';
import BrandingTab from '../components/settings/BrandingTab.vue';
import ImportExportTab from '../components/settings/ImportExportTab.vue';
import WorkflowsTab from '../components/settings/WorkflowsTab.vue';
import RemindersTab from '../components/settings/RemindersTab.vue';
import IntegrationsTab from '../components/settings/IntegrationsTab.vue';
import ApiWebhooksTab from '../components/settings/ApiWebhooksTab.vue';
import BackupsTab from '../components/settings/BackupsTab.vue';

/** Company settings and system administration. The open tab is kept in ?tab= so other pages can deep-link. */
const route = useRoute();
const router = useRouter();

const tabs = [
    { key: 'company', label: 'Company settings', icon: SlidersHorizontal, component: CompanySettingsTab },
    { key: 'branding', label: 'Branding', icon: Palette, component: BrandingTab },
    { key: 'workflows', label: 'Workflows', icon: Workflow, component: WorkflowsTab },
    { key: 'reminders', label: 'Reminders', icon: BellRing, component: RemindersTab },
    { key: 'import', label: 'Import & export', icon: FileSpreadsheet, component: ImportExportTab },
    { key: 'integrations', label: 'Integrations', icon: Plug, component: IntegrationsTab },
    { key: 'api', label: 'API & webhooks', icon: Webhook, component: ApiWebhooksTab },
    { key: 'backups', label: 'Data & backups', icon: DatabaseBackup, component: BackupsTab },
];
const valid = (t) => tabs.some((x) => x.key === t);
const tab = ref(valid(route.query.tab) ? route.query.tab : 'company');
const companyKey = ref(0); // remounts the tab after a super admin switches company

function switchTab(t) {
    tab.value = t;
    router.replace({ query: { ...route.query, tab: t } });
}
watch(() => route.query.tab, (t) => { if (valid(t)) tab.value = t; });
</script>

<template>
    <div>
        <PageHeader title="Settings" subtitle="Company rules, branding, approval workflows, reminders, data and integrations.">
            <CompanyPicker @change="companyKey++" />
        </PageHeader>
        <Tabs :tabs="tabs" :model-value="tab" @update:model-value="switchTab" />
        <component :is="tabs.find((t) => t.key === tab).component" :key="`${tab}-${companyKey}`" />
    </div>
</template>
