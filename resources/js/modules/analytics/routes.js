export default [
    { path: 'analytics', name: 'analytics', component: () => import('./pages/HrDashboard.vue'), meta: { title: 'HR Dashboard', can: 'dashboard.hr' } },
    { path: 'analytics/reports', name: 'analytics.reports', component: () => import('./pages/AnalyticsReports.vue'), meta: { title: 'Analytics Reports', can: 'dashboard.hr' } },
    { path: 'analytics/ai', name: 'analytics.ai', component: () => import('./pages/AiInsights.vue'), meta: { title: 'AI Insights', can: 'dashboard.hr' } },
    // Legacy links open the matching Analytics Reports tab.
    { path: 'analytics/headcount', redirect: { path: '/analytics/reports', query: { tab: 'headcount' } } },
    { path: 'analytics/payroll-cost', redirect: { path: '/analytics/reports', query: { tab: 'payroll-cost' } } },
    { path: 'analytics/absenteeism', redirect: { path: '/analytics/reports', query: { tab: 'absenteeism' } } },
    { path: 'analytics/pay-gap', redirect: { path: '/analytics/reports', query: { tab: 'pay-gap' } } },
    { path: 'analytics/attrition', redirect: { path: '/analytics/reports', query: { tab: 'attrition' } } },
    { path: 'analytics/workforce', redirect: { path: '/analytics/reports', query: { tab: 'workforce' } } },
];
