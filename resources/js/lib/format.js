export function formatDate(value, opts = { day: 'numeric', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-KE', opts);
}

export function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-KE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function timeAgo(value) {
    if (!value) return '';
    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);
    const units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    for (const [unit, s] of units) {
        if (Math.abs(seconds) >= s) return rtf.format(-Math.round(seconds / s), unit);
    }
    return 'just now';
}

export function formatMoney(value, currency = 'KES') {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('en-KE', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
}

export function formatNumber(value) {
    return new Intl.NumberFormat('en-KE').format(value ?? 0);
}

export function titleCase(value) {
    return String(value ?? '').replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function initials(name) {
    return String(name ?? '').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

/** True for a finished YYYY-MM-DD value with a plausible year (date inputs emit 0002-…, 0020-… while a year is typed). */
export function isCompleteDate(value) {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && Number(value.slice(0, 4)) >= 2000;
}

// Words that should not be sentence-cased when shown to users.
const LABEL_WORDS = {
    mpesa: 'M-Pesa', kra: 'KRA', pin: 'PIN', paye: 'PAYE', nssf: 'NSSF', shif: 'SHIF', nhif: 'NHIF', helb: 'HELB',
    ahl: 'AHL', p9a: 'P9A', p10: 'P10', hr: 'HR', hod: 'HOD', id: 'ID', ai: 'AI', it: 'IT', ceo: 'CEO', pdf: 'PDF',
    csv: 'CSV', api: 'API', sms: 'SMS', eft: 'EFT', rtgs: 'RTGS', kes: 'KES', ot: 'OT', pip: 'PIP', ess: 'ESS',
};
const LABEL_EXACT = { mpesa: 'M-Pesa', n_a: 'N/A', na: 'N/A' };

/** True for machine values such as `pending_approval` or `mpesa` that should go through label(). */
export function isRawValue(value) {
    return typeof value === 'string' && /^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$/.test(value);
}

/**
 * Human label for a machine value: `employer_contribution` → "Employer contribution", `mpesa` → "M-Pesa",
 * `paye` → "PAYE". Text that is already human (has capitals or spaces) is returned unchanged.
 */
export function label(value) {
    if (value === null || value === undefined || value === '') return '';
    const text = String(value).trim();
    if (!isRawValue(text)) return text;
    if (LABEL_EXACT[text]) return LABEL_EXACT[text];
    return text.split('_').filter(Boolean).map((word, i) => {
        if (LABEL_WORDS[word]) return LABEL_WORDS[word];
        return i === 0 ? word.charAt(0).toUpperCase() + word.slice(1) : word;
    }).join(' ');
}
