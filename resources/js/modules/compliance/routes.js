export default [
    { path: 'compliance', name: 'compliance', component: () => import('./pages/ComplianceCases.vue'), meta: { title: 'Disciplinary & Grievances', can: 'employees.update' } },
    { path: 'compliance/hs', name: 'compliance.hs', component: () => import('./pages/HealthSafetyHub.vue'), meta: { title: 'Health & Safety', can: 'employees.update' } },
    { path: 'compliance/data-protection', name: 'compliance.data-protection', component: () => import('./pages/DataProtection.vue'), meta: { title: 'Data Protection', can: 'settings.manage' } },
    // Registers with no other screen
    { path: 'compliance/crb', name: 'compliance.crb', component: () => import('./pages/CrbGoodConduct.vue'), meta: { title: 'CRB & Good Conduct', can: 'employees.update' } },
    { path: 'compliance/medical', name: 'compliance.medical', component: () => import('./pages/MedicalInsurance.vue'), meta: { title: 'Medical & Insurance', can: 'employees.update' } },
    // Exit & Clearance and KYC now live in Employee Detail tabs
    { path: 'compliance/exits', name: 'compliance.exits', component: () => import('./pages/ExitClearance.vue'), meta: { title: 'Exit & Clearance', can: 'employees.exit' } },
    { path: 'compliance/verification', redirect: '/employees' },
    // legacy redirects
    { path: 'compliance/disciplinary', redirect: { path: '/compliance', query: { tab: 'disciplinary' } } },
    { path: 'compliance/grievances', redirect: { path: '/compliance', query: { tab: 'grievances' } } },
    { path: 'compliance/warnings', redirect: { path: '/compliance', query: { tab: 'warnings' } } },
    { path: 'compliance/health-safety', redirect: '/compliance/hs' },
    { path: 'compliance/incidents', redirect: '/compliance/hs' },
    { path: 'compliance/kyc', redirect: '/employees' },
];
