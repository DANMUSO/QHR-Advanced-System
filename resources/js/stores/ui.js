import { defineStore } from 'pinia';

let toastId = 0;
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
        setCompanyScope(id) {
            this.companyScope = id ? String(id) : '';
            if (id) localStorage.setItem('companyScope', String(id));
            else localStorage.removeItem('companyScope');
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
