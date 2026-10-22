export default [
    // Staff read their file and company documents in My Portal → Documents.
    { path: 'documents', redirect: { path: '/portal', query: { tab: 'documents' } } },
    { path: 'documents/my-file', redirect: { path: '/portal', query: { tab: 'documents' } } },
    { path: 'documents/company', name: 'documents.company', component: () => import('./pages/CompanyDocuments.vue'), meta: { title: 'Memos & Policies', can: 'documents.manage' } },
    { path: 'documents/employees', name: 'documents.employees', component: () => import('./pages/EmployeeDocuments.vue'), meta: { title: 'Employee Files', can: 'documents.manage' } },
    { path: 'documents/employees/:id', name: 'documents.employee', component: () => import('./pages/EmployeeDocuments.vue'), meta: { title: 'Employee File', can: 'documents.manage' } },
    { path: 'documents/expiry', name: 'documents.expiry', component: () => import('./pages/DocumentExpiry.vue'), meta: { title: 'Document Expiry Alerts', can: 'documents.manage' } },
];
