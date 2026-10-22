export default [
    { path: 'probation', name: 'probation', component: () => import('./pages/ProbationList.vue'), meta: { title: 'Probation', can: 'probation.manage' } },
    // Supervisors (and anyone a review stage routes to) comment on their reports' probation reviews.
    { path: 'team/probation', name: 'team.probation', component: () => import('./pages/TeamProbationReviews.vue'), meta: { title: 'Probation reviews', can: ['performance.manage-team', 'probation.manage'] } },
];
