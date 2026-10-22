export default [
    { path: 'payroll', name: 'payroll', component: () => import('./pages/PayrollRuns.vue'), meta: { title: 'Payroll', can: ['payroll.manage', 'payroll.review', 'payroll.approve'] } },
    // Report users open runs from the Outputs page (read + export only).
    { path: 'payroll/runs/:id', name: 'payroll.run', component: () => import('./pages/PayrollRunDetail.vue'), meta: { title: 'Payroll Run', can: ['payroll.manage', 'payroll.review', 'payroll.approve', 'payroll.reports'] } },
    { path: 'payroll/setup', name: 'payroll.setup', component: () => import('./pages/PayrollSetup.vue'), meta: { title: 'Payroll Setup', can: 'payroll.manage' } },
    { path: 'payroll/outputs', name: 'payroll.outputs', component: () => import('./pages/PayrollOutputs.vue'), meta: { title: 'Reports & Outputs', can: ['payroll.manage', 'payroll.approve', 'payroll.reports'] } },
    // legacy deep-links (no page of their own); query strings (e.g. ?final_dues=<id> from the Exit page) are kept
    { path: 'payroll/runs', redirect: (to) => ({ path: '/payroll', query: to.query }) },
    { path: 'payroll/my-payslips', redirect: '/portal' },
    { path: 'payroll/payslips', redirect: '/portal' },
    { path: 'payroll/loans', redirect: { path: '/payroll', query: { tab: 'loans' } } },
    { path: 'payroll/advances', redirect: { path: '/payroll', query: { tab: 'advances' } } },
    { path: 'payroll/structures', redirect: '/payroll/setup' },
    { path: 'payroll/components', redirect: '/payroll/setup' },
    { path: 'payroll/statutory', redirect: '/payroll/setup' },
    { path: 'payroll/calendar', redirect: '/payroll/setup' },
    { path: 'payroll/reports', redirect: '/payroll/outputs' },
    { path: 'payroll/variance', redirect: '/payroll/outputs' },
    { path: 'payroll/payment-files', redirect: '/payroll/outputs' },
    { path: 'payroll/returns', redirect: '/payroll/outputs' },
];
