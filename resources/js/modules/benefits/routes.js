import { useAuthStore } from '@/stores/auth';

export default [
    { path: 'benefits', name: 'benefits', component: () => import('./pages/BenefitsHub.vue'), meta: { title: 'Benefits & Pension', can: ['settings.manage', 'organization.manage'] } },
    { path: 'benefits/pension', redirect: { path: '/benefits', query: { tab: 'pension' } } },
    { path: 'claims/reports', name: 'claims.reports', component: () => import('./pages/FinanceReports.vue'), meta: { title: 'Finance Reports', can: 'claims.reports' } },
    // Approval queues. No `can`: supervisors approve their reports' items without a dedicated permission; the API authorises.
    { path: 'expenses/approvals', name: 'expenses.approvals', component: () => import('./pages/ExpenseApprovals.vue'), meta: { title: 'Expense & Travel Approvals' } },
    { path: 'expenses/payments', name: 'expenses.payments', component: () => import('./pages/ExpensePayments.vue'), meta: { title: 'Expense Payments', can: 'claims.pay' } },
    // Old combined page: send each user to the claims queue they can act on.
    { path: 'finance/approvals', redirect: () => (useAuthStore().can('claims.pay') ? '/claims/payments' : '/claims/approvals') },
    // Self-service expenses, travel and advances live in My Portal.
    { path: 'expenses', redirect: '/portal' },
    { path: 'expenses/travel', redirect: '/portal' },
    { path: 'expenses/advance', redirect: '/portal' },
];
