import { ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

/**
 * Company context for single-company admin screens (structure, settings).
 * Normal users are pinned to their company. A super admin uses the global company scope (top bar) when set,
 * otherwise picks a company here; requests then carry an X-Company-Id header for that company.
 */
const picked = ref('');

export function useCompanyContext() {
    const auth = useAuthStore();
    const ui = useUiStore();

    const needsPicker = computed(() => auth.isSuperAdmin && !ui.companyScope);
    const companies = computed(() => auth.user?.companies ?? []);
    if (needsPicker.value && !picked.value && companies.value.length) picked.value = String(companies.value[0].id);

    const companyId = computed(() => (needsPicker.value ? picked.value : String(ui.companyScope || auth.user?.company?.id || '')));
    const companyName = computed(() => companies.value.find((c) => String(c.id) === companyId.value)?.name ?? auth.user?.company?.name ?? '');

    /** Axios config with the company header added when the super admin picked a company here. */
    function cfg(config = {}) {
        if (!needsPicker.value || !picked.value) return config;
        return { ...config, headers: { ...(config.headers ?? {}), 'X-Company-Id': picked.value } };
    }

    return { picked, needsPicker, companies, companyId, companyName, cfg };
}
