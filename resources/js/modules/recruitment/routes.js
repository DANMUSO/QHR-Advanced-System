export default [
    { path: 'recruitment', name: 'recruitment', component: () => import('./pages/RecruitmentPipeline.vue'), meta: { title: 'Recruitment Pipeline', can: ['recruitment.manage', 'recruitment.requisition'] } },
    { path: 'recruitment/reports', name: 'recruitment.reports', component: () => import('./pages/HiringReports.vue'), meta: { title: 'Hiring Reports', can: ['recruitment.reports', 'recruitment.manage'] } },
    // Legacy paths → the matching pipeline tab (the public careers page is registered in router/index.js)
    ...['requisitions', 'postings', 'applicants', 'interviews', 'offers', 'onboarding', 'induction', 'assets']
        .map((tab) => ({ path: `recruitment/${tab}`, redirect: { path: '/recruitment', query: { tab } } })),
];
