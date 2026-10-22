export default [
    { path: 'organization', name: 'organization', component: () => import('./pages/OrgOverview.vue'), meta: { title: 'Structure', can: 'organization.manage' } },
    { path: 'organization/companies', name: 'organization.companies', component: () => import('./pages/Companies.vue'), meta: { title: 'Companies', can: 'companies.manage' } },
    { path: 'organization/users', name: 'organization.users', component: () => import('./pages/UserAdmin.vue'), meta: { title: 'Users & Roles', can: 'users.manage' } },
    { path: 'organization/users/:id(\\d+)', name: 'organization.users.show', component: () => import('./pages/UserDetail.vue'), meta: { title: 'Manage user', can: 'users.manage' } },
    { path: 'organization/settings', name: 'organization.settings', component: () => import('./pages/SystemSettings.vue'), meta: { title: 'Settings', can: 'settings.manage' } },
    // Every signed-in user can raise and follow their own tickets; settings.manage sees the company's tickets.
    { path: 'organization/support', name: 'organization.support', component: () => import('./pages/SupportTickets.vue'), meta: { title: 'Help & Support' } },
    // Old standalone pages now live as tabs of Structure (/organization?tab=…) and Settings (/organization/settings?tab=…).
    { path: 'organization/branches', redirect: { path: '/organization', query: { tab: 'branches' } } },
    { path: 'organization/departments', redirect: { path: '/organization', query: { tab: 'departments' } } },
    { path: 'organization/positions', redirect: { path: '/organization', query: { tab: 'positions' } } },
    { path: 'organization/grades', redirect: { path: '/organization', query: { tab: 'levels' } } },
    { path: 'organization/company-settings', redirect: { path: '/organization/settings', query: { tab: 'company' } } },
    { path: 'organization/branding', redirect: { path: '/organization/settings', query: { tab: 'branding' } } },
    { path: 'organization/workflows', redirect: { path: '/organization/settings', query: { tab: 'workflows' } } },
    { path: 'organization/reminders', redirect: { path: '/organization/settings', query: { tab: 'reminders' } } },
    { path: 'organization/bulk', redirect: { path: '/organization/settings', query: { tab: 'import' } } },
    { path: 'organization/data', redirect: { path: '/organization/settings', query: { tab: 'backups' } } },
    { path: 'organization/integrations', redirect: { path: '/organization/settings', query: { tab: 'integrations' } } },
    { path: 'organization/api', redirect: { path: '/organization/settings', query: { tab: 'api' } } },
    { path: 'organization/announcements', redirect: '/announcements' },
];
