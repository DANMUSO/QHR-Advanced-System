export default [
    { path: 'employees', name: 'employees', component: () => import('./pages/EmployeeList.vue'), meta: { title: 'Employees', can: 'employees.view' } },
    // /employees/new?applicant_id=X — "Convert to employee" from Recruitment (the form fetches the prefill)
    { path: 'employees/contracts', name: 'employees.contracts', component: () => import('./pages/Contracts.vue'), meta: { title: 'Contracts', can: 'employees.view' } },
    { path: 'employees/new', name: 'employees.new', component: () => import('./pages/EmployeeList.vue'), meta: { title: 'Add Employee', can: 'employees.create' } },
    { path: 'employees/org-chart', name: 'employees.org-chart', component: () => import('./pages/OrgChart.vue'), meta: { title: 'Org Chart', can: 'employees.view' } },
    { path: 'employees/reports', name: 'employees.reports', component: () => import('./pages/StaffReport.vue'), meta: { title: 'Staff report', can: 'employees.view' } },
    { path: 'employees/lifecycle', name: 'employees.lifecycle', component: () => import('./pages/EmployeeLifecycle.vue'), meta: { title: 'Employee lifecycle', can: 'employees.view' } },
    { path: 'employees/records', name: 'employees.records', component: () => import('./pages/EmployeeRecords.vue'), meta: { title: 'Records & KYC', can: 'employees.update' } },
    { path: 'employees/requests', name: 'employees.requests', component: () => import('./pages/ProfileChangeRequests.vue'), meta: { title: 'Staff requests', can: 'employees.update' } },
    // Numeric ids only, so named sub-pages (reports, lifecycle, records…) are never swallowed by the profile route.
    { path: 'employees/:id(\\d+)', name: 'employees.show', component: () => import('./pages/EmployeeDetail.vue'), meta: { title: 'Employee' } },
    { path: 'employees/:id(\\d+)/edit', redirect: to => `/employees/${to.params.id}` },
    { path: 'portal', name: 'portal', component: () => import('./pages/MyPortal.vue'), meta: { title: 'My Portal' } },
    // Legacy paths
    { path: 'my', redirect: '/portal' },
    { path: 'employees/profile', redirect: '/portal' },
    { path: 'profile', redirect: '/portal' },
    { path: 'employees/letters', redirect: '/employees' },
    { path: 'employees/transfers', redirect: '/employees/lifecycle?tab=moves' },
    { path: 'employees/exits', redirect: '/employees/lifecycle' },
    { path: 'employees/kyc', redirect: '/employees/records' },
];
