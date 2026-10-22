import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { basePath } from '@/lib/api';

/**
 * A page rendered inside the split-screen guest layout. Each guest page gets its own parent route:
 * a shared parent at "/" would itself match "/" and render an empty layout instead of the dashboard redirect.
 */
function guestPage(path, name, component, meta = {}, props = undefined) {
    return {
        path,
        component: () => import('@/layouts/GuestLayout.vue'),
        meta: { guest: true, ...meta },
        children: [{ path: '', name, component, meta, props }],
    };
}

const routes = [
    // Guest pages
    guestPage('/login', 'login', () => import('@/pages/auth/Login.vue'), { title: 'Sign in' }),
    guestPage('/forgot-password', 'forgot-password', () => import('@/pages/auth/ForgotPassword.vue'), { title: 'Forgot password' }),
    guestPage('/reset-password', 'reset-password', () => import('@/pages/auth/ResetPassword.vue'), { title: 'Reset password', public: true }),
    guestPage('/activate', 'activate', () => import('@/pages/auth/ResetPassword.vue'), { title: 'Activate account', public: true }, { mode: 'activate' }),

    // Public careers page (Recruitment): open to everyone, signed in or not.
    { path: '/careers/:slug?', name: 'careers', component: () => import('@/modules/recruitment/pages/Careers.vue'), meta: { public: true, title: 'Careers' } },

    // Forced account securing (temporary password / mandatory 2FA)
    {
        path: '/secure-account',
        component: () => import('@/layouts/GuestLayout.vue'),
        meta: { auth: true, securing: true },
        children: [
            { path: '', name: 'secure-account', component: () => import('@/pages/account/SecureAccount.vue'), meta: { title: 'Secure your account' } },
        ],
    },

    // Application
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        meta: { auth: true },
        children: [
            { path: '', name: 'dashboard', component: () => import('@/pages/Dashboard.vue'), meta: { title: 'Dashboard' } },
            { path: 'account/security', name: 'account.security', component: () => import('@/pages/account/Security.vue'), meta: { title: 'Account security' } },
            { path: 'announcements', name: 'announcements', component: () => import('@/pages/Announcements.vue'), meta: { title: 'Announcements' } },
            { path: 'admin/login-activity', name: 'admin.login-activity', component: () => import('@/pages/admin/LoginActivity.vue'), meta: { title: 'Sign-in activity', can: 'audit.view' } },
            { path: 'admin/audit-log', name: 'admin.audit-log', component: () => import('@/pages/admin/AuditLog.vue'), meta: { title: 'Audit trail', can: 'audit.view' } },
            // Module routes (resources/js/modules/<module>/routes.js) are appended below.
            ...Object.values(import.meta.glob('../modules/*/routes.js', { eager: true })).flatMap((m) => m.default),
            { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFound.vue'), meta: { title: 'Not found' } },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(basePath),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) await auth.fetchUser();

    const isPublic = to.matched.some((r) => r.meta.public);
    const guestOnly = to.matched.some((r) => r.meta.guest) && !isPublic;
    const needsAuth = to.matched.some((r) => r.meta.auth);

    if (needsAuth && !auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }
    if (guestOnly && auth.isAuthenticated) {
        return { name: 'dashboard' };
    }
    if (auth.isAuthenticated && (auth.needsSecuritySetup || auth.securityBlock) && !to.meta.securing && !isPublic) {
        return { name: 'secure-account' };
    }
    if (to.meta.securing && auth.isAuthenticated && !auth.needsSecuritySetup && !auth.securityBlock) {
        return { name: 'dashboard' };
    }
    if (to.meta.can && !auth.can(to.meta.can)) {
        return { name: 'dashboard' };
    }
});

router.afterEach((to) => {
    document.title = [to.meta.title, 'HR Advanced'].filter(Boolean).join(' · ');
});

export default router;
