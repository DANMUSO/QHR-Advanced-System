const manage = (tab) => ({ path: '/performance/manage', query: { tab } });

export default [
    // Employee self-service: KPIs (accept / cascade / convert / review / submit), appraisals, coaching, recognition, learning
    { path: 'performance', name: 'performance', component: () => import('./pages/MyPerformance.vue'), meta: { title: 'My Performance', can: 'performance.participate' } },
    { path: 'performance/appraisals', redirect: { path: '/performance', query: { tab: 'appraisals' } } },
    { path: 'performance/pips/:id', redirect: { path: '/performance', query: { tab: 'coaching' } } },
    { path: 'performance/reviews/:id', redirect: { path: '/performance', query: { tab: 'appraisals' } } },
    { path: 'learning/my', redirect: { path: '/performance', query: { tab: 'learning' } } },

    // Supervisor / HR workspace
    { path: 'performance/manage', name: 'performance.manage', component: () => import('./pages/PerformanceManagement.vue'), meta: { title: 'Performance Management', can: 'performance.manage-team' } },
    { path: 'learning', name: 'learning', component: () => import('./pages/LearningDevelopment.vue'), meta: { title: 'Learning & Development', can: 'performance.admin' } },
    { path: 'succession', name: 'succession', component: () => import('./pages/SuccessionHub.vue'), meta: { title: 'Succession & Talent', can: 'performance.admin' } },

    // Legacy links → the matching tab
    { path: 'performance/cycles', redirect: manage('cycles') },
    { path: 'performance/team', redirect: manage('team') },
    { path: 'performance/plans', redirect: manage('plans') },
    { path: 'performance/reviews', redirect: manage('reviews') },
    { path: 'performance/coaching', redirect: manage('coaching') },
    { path: 'performance/recognition', redirect: manage('recognition') },
    { path: 'performance/reports', redirect: manage('reports') },
    { path: 'performance/calibration', redirect: manage('calibration') },
    { path: 'learning/catalogue', redirect: '/learning' },
    { path: 'learning/skills', redirect: '/learning' },
    { path: 'learning/certifications', redirect: '/learning' },
    { path: 'succession/careers', redirect: '/succession' },
    { path: 'succession/talent-pools', redirect: '/succession' },
    { path: 'succession/9box', redirect: '/succession' },
];
