/**
 * Client-side CSV export (opens in Excel). `columns` = [{ key, label, value?: (row) => any }].
 */
export function downloadCsv(filename, columns, rows) {
    const escape = (v) => {
        const s = v === null || v === undefined ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const header = columns.map((c) => escape(c.label)).join(',');
    const body = rows.map((r) => columns.map((c) => escape(c.value ? c.value(r) : getPath(r, c.key))).join(',')).join('\n');
    const blob = new Blob(['﻿' + header + '\n' + body], { type: 'text/csv;charset=utf-8' });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: filename.endsWith('.csv') ? filename : `${filename}.csv` });
    a.click();
    URL.revokeObjectURL(a.href);
}

export function getPath(obj, path) {
    return String(path).split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
}

/** Opens a server-generated file (PDF/CSV) — keeps cookies, works from a sub-folder. */
export function openServerFile(path) {
    window.open(`${window.APP_BASE || ''}${path}`, '_blank');
}
