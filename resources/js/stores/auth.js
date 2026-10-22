import { defineStore } from 'pinia';
import api from '@/lib/api';
import { useUiStore } from '@/stores/ui';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        loaded: false,
        securityBlock: null, // 'password_change_required' | 'two_factor_setup_required'
    }),

    getters: {
        isAuthenticated: (s) => !!s.user,
        permissions: (s) => new Set(s.user?.permissions ?? []),
        roles: (s) => s.user?.roles ?? [],
        isSuperAdmin: (s) => !!s.user?.is_super_admin,
        isSupervisor: (s) => !!s.user?.employee?.is_supervisor,
        needsSecuritySetup: (s) =>
            !!s.user && !s.user.impersonator && (s.user.security.must_change_password || s.user.security.needs_two_factor_setup),
    },

    actions: {
        can(permission) {
            if (!permission) return true;
            if (this.isSuperAdmin) return true;
            const list = Array.isArray(permission) ? permission : [permission];
            return list.some((p) => this.permissions.has(p));
        },

        hasRole(role) {
            return this.roles.includes(role);
        },

        async fetchUser() {
            try {
                const { data } = await api.get('/auth/me');
                this.user = data.data ?? data;
                this._autoSelectCompany();
            } catch {
                this.user = null;
            } finally {
                this.loaded = true;
            }
            return this.user;
        },

        setUser(user) {
            this.user = user?.data ?? user;
            this.loaded = true;
            this.securityBlock = null;
            this._autoSelectCompany();
        },

        _autoSelectCompany() {
            if (this.isSuperAdmin && !localStorage.getItem('companyScope') && this.user?.companies?.length) {
                useUiStore().setCompanyScope(String(this.user.companies[0].id));
            }
        },

        async login(credentials) {
            const { data } = await api.post('/auth/login', credentials);
            if (data.two_factor) return { twoFactor: true };
            this.setUser(data.user);
            return { twoFactor: false };
        },

        async twoFactorChallenge(payload) {
            const { data } = await api.post('/auth/two-factor-challenge', payload);
            this.setUser(data.user);
        },

        async logout() {
            try {
                await api.post('/auth/logout');
            } finally {
                this.user = null;
                localStorage.removeItem('companyScope');
            }
        },

        async stopImpersonating() {
            const { data } = await api.post('/auth/impersonate/stop');
            this.setUser(data.user);
        },

        async impersonate(userId) {
            const { data } = await api.post(`/admin/impersonate/${userId}`);
            this.setUser(data.user);
        },

        async savePreferences(prefs) {
            const { data } = await api.put('/me/preferences', prefs);
            if (this.user) this.user.preferences = data.preferences;
        },
    },
});
