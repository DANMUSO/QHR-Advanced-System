import { FileText } from 'lucide-vue-next';

// Staff document self-service (My Files + Company Policies) lives in My Portal.
// Employee files and expiry alerts are reached from the Employees section; company documents are managed here.
export default [
    { section: 'Employees', label: 'Memos & Policies', to: '/documents/company', icon: FileText, can: 'documents.manage', order: 14 },
];
