export default [
    { path: 'engagement', name: 'engagement', component: () => import('./pages/MyComments.vue'), meta: { title: 'Suggestion Box' } },
    { path: 'engagement/manage', name: 'engagement.manage', component: () => import('./pages/EngagementManage.vue'), meta: { title: 'Staff Engagement', can: 'engagement.manage' } },
];
