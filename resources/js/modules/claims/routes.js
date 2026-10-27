import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';

// claims.approve holders, or anyone a claim was routed / escalated to (server decides: GET /claims/approvals/access).
async function approverOnly() {
    if (useAuthStore().can('claims.approve')) return true;
    try {
        const { data } = await api.get('/claims/approvals/access');
        return data.allowed ? true : { name: 'dashboard' };
    } catch {
        return { name: 'dashboard' };
    }
}

export default [
    { path: 'claims', name: 'claims', component: () => import('./pages/MyClaims.vue'), meta: { title: 'My Claims', can: 'claims.submit' } },
    { path: 'claims/new', redirect: '/claims' },
    // No `can`: a claim may be routed to an approver without claims.approve (escalation); the guard asks the server.
    { path: 'claims/approvals', name: 'claims.approvals', component: () => import('./pages/ClaimApprovals.vue'), meta: { title: 'Claim Approvals' }, beforeEnter: approverOnly },
    { path: 'claims/payments', name: 'claims.payments', component: () => import('./pages/ClaimPayments.vue'), meta: { title: 'Claim Payments', can: 'claims.pay' } },
    { path: 'claims/settings', name: 'claims.settings', component: () => import('./pages/ClaimSettings.vue'), meta: { title: 'Claim Settings', can: ['settings.manage', 'claims.approve'] } },
];
