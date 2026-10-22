import api from '@/lib/api';
import { titleCase } from '@/lib/format';

/** Badge tones (see components/ui/Badge.vue) for the module's statuses. */
export const statusTone = {
    active: 'green', suspended: 'amber', exited: 'red', transferred: 'slate',
    on_probation: 'amber', extended: 'amber', confirmed: 'green', terminated: 'red', none: 'slate',
    draft: 'slate', expired: 'red', superseded: 'slate',
    scheduled: 'sky', completed: 'green', cancelled: 'slate',
    pending: 'amber', in_progress: 'sky', cleared: 'green',
    verified: 'green', failed: 'red', not_submitted: 'slate',
    transfer: 'sky', promotion: 'green', demotion: 'red', redeployment: 'amber', company_transfer: 'brand',
};

export const tone = (status) => statusTone[status] ?? 'slate';
export const label = (v) => (v ? titleCase(v) : '—');

export const CONTRACT_TYPES = [
    { value: 'permanent', label: 'Permanent' },
    { value: 'contract', label: 'Contract' },
    { value: 'casual', label: 'Casual' },
    { value: 'intern', label: 'Intern' },
];

export const CONTRACT_DOC_TYPES = [
    { value: 'permanent', label: 'Permanent' },
    { value: 'fixed_term', label: 'Fixed term' },
    { value: 'casual', label: 'Casual' },
    { value: 'probation', label: 'Probationary' },
    { value: 'internship', label: 'Internship' },
];

let metaPromise = null;
/** Cached GET /employees/meta (relationships, exit reasons, ...). */
export function loadMeta() {
    metaPromise ??= api.get('/employees/meta').then((r) => r.data).catch((e) => { metaPromise = null; throw e; });
    return metaPromise;
}

/** Downloads an authenticated API file (CSV/PDF) as a blob, keeping the company header and base path. */
export async function downloadFile(path, params = {}, fallbackName = 'download') {
    const { data, headers } = await api.get(path.replace(/^\/api/, ''), { params, responseType: 'blob' });
    const match = /filename="?([^";]+)"?/.exec(headers['content-disposition'] || '');
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(data), download: match?.[1] ?? fallbackName });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/** Opens an authenticated PDF in a new tab (fetched as a blob so it works from a sub-folder too). */
export async function openPdf(path) {
    const win = window.open('', '_blank');
    try {
        const { data } = await api.get(path.replace(/^\/api/, ''), { responseType: 'blob' });
        const url = URL.createObjectURL(new Blob([data], { type: 'application/pdf' }));
        if (win) win.location.href = url; else window.open(url, '_blank');
    } catch (e) {
        win?.close();
        throw e;
    }
}

export function paginatorOf(data) {
    return { current_page: data.current_page, last_page: data.last_page, total: data.total, from: data.from, to: data.to };
}

/** Removes empty values so query strings stay clean. */
export function cleanParams(obj) {
    return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

/** "Leaving 20 Nov" for staff with a scheduled (future-dated) exit. */
export const leavingLabel = (date) => (date ? `Leaving ${new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}` : '');
