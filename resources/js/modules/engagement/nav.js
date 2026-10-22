import { MessageSquareHeart, Inbox } from 'lucide-vue-next';

// Analytics links live in modules/analytics/nav.js (they were duplicated here).
export default [
    { section: 'Self-Service', label: 'Suggestion Box',   to: '/engagement',        icon: Inbox,              order: 60, hasEmployee: true },
    { section: 'Performance',  label: 'Staff Engagement', to: '/engagement/manage', icon: MessageSquareHeart, can: 'engagement.manage', order: 40 },
];
