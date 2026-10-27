import { defineStore } from 'pinia';

let toastId = 0;

function flashNotice(message) {
    try { sessionStorage.setItem('companyNotice', message); } catch { /* storage unavailable */ }
}

/** For a record page (route with an :id-style param) the path of its list page, e.g. /employees/12/edit -> /employees; otherwise null. */
export function recordListPath(router, route) {
    const params = Object.entries(route.params ?? {}).filter(([k, v]) => k !== 'pathMatch' && v !== '' && v != null);
    const record = route.matched?.[route.matched.length - 1];
    if (!params.length || !record) return null;
    // Walk up from the path before the first param until a real page matches (e.g. /payroll/runs -> /payroll).
    const parts = record.path.split('/:')[0].split('/').filter(Boolean);
    while (parts.length) {
        const resolved = router.resolve('/' + parts.join('/'));
        if (resolved.matched.length && resolved.name !== 'not-found') return resolved.fullPath;
        parts.pop();
    }
    return '/';
}
const media = window.matchMedia('(prefers-color-scheme: dark)');

export const useUiStore = defineStore('ui', {
    state: () => ({
        theme: localStorage.getItem('theme') || 'system',
        sidebarOpen: false, // mobile drawer
        sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === '1',
        commandPaletteOpen: false,
        companyScope: localStorage.getItem('companyScope') || '',
        companyRequired: false, // set when the current page needs a single company selected
        toasts: [],
    }),

    getters: {
        isDark: (s) => s.theme === 'dark' || (s.theme === 'system' && media.matches),
    },

    actions: {
        applyTheme() {
            document.documentElement.classList.toggle('dark', this.isDark);
        },
        setTheme(theme) {
            this.theme = theme;
            localStorage.setItem('theme', theme);
            this.applyTheme();
        },
        cycleTheme() {
            const order = ['light', 'dark', 'system'];
            this.setTheme(order[(order.indexOf(this.theme) + 1) % order.length]);
        },
        toggleCollapsed() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
        },
        /**
         * Super admin company scope. A company id is sent as X-Company-Id; "All companies" is remembered
         * separately (companyScopeAll) so a reload does not fall back to the first company.
         */
        setCompanyScope(id) {
            this.companyScope = id ? String(id) : '';
            try {
                if (id) {
                    localStorage.setItem('companyScope', String(id));
                    localStorage.removeItem('companyScopeAll');
                } else {
                    localStorage.removeItem('companyScope');
                    localStorage.setItem('companyScopeAll', '1');
                }
            } catch { /* storage unavailable */ }
        },
        /**
         * Switches the company and reloads, so every widget and list refetches for the new company.
         * On a record page (a route with an id) the record most likely belongs to the previous company,
         * so the user lands on that page's list with a note instead of a "not found" screen.
         */
        switchCompany(id, router) {
            this.setCompanyScope(id);
            const route = router.currentRoute.value;
            const target = recordListPath(router, route);
            if (target) flashNotice('You switched company, so the record you had open was closed. Here is the list for the selected company.');
            window.location.assign(router.resolve(target ?? route.fullPath).href);
        },
        /** A record page could not be loaded for the current company: back to its list with a message. */
        leaveForeignRecord(router, message = 'That record is not part of the selected company (or no longer exists), so we took you back to the list.') {
            const target = recordListPath(router, router.currentRoute.value);
            if (!target) return false;
            this.toast(message, 'info', 6000);
            router.replace(target);
            return true;
        },
        showFlashNotice() {
            try {
                const msg = sessionStorage.getItem('companyNotice');
                if (msg) {
                    sessionStorage.removeItem('companyNotice');
                    this.toast(msg, 'info', 6000);
                }
            } catch { /* storage unavailable */ }
        },
        toast(message, type = 'success', timeout = 4000) {
            const id = ++toastId;
            this.toasts.push({ id, message, type });
            if (timeout) setTimeout(() => this.dismiss(id), timeout);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    },
});

media.addEventListener?.('change', () => {
    const ui = useUiStore();
    if (ui.theme === 'system') ui.applyTheme();
});
