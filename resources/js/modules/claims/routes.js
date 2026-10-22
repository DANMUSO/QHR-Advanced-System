export default [
    { path: 'claims', name: 'claims', component: () => import('./pages/MyClaims.vue'), meta: { title: 'My Claims', can: 'claims.submit' } },
    { path: 'claims/new', redirect: '/claims' },
    // No `can`: a claim may be routed to an approver without claims.approve (escalation); the API authorises each item.
    { path: 'claims/approvals', name: 'claims.approvals', component: () => import('./pages/ClaimApprovals.vue'), meta: { title: 'Claim Approvals' } },
    { path: 'claims/payments', name: 'claims.payments', component: () => import('./pages/ClaimPayments.vue'), meta: { title: 'Claim Payments', can: 'claims.pay' } },
    { path: 'claims/settings', name: 'claims.settings', component: () => import('./pages/ClaimSettings.vue'), meta: { title: 'Claim Settings', can: ['settings.manage', 'claims.approve'] } },
];
