import axios from 'axios';

/**
 * Axios instance for the Laravel API (Sanctum cookie-based SPA auth).
 * - fetches the CSRF cookie once before the first state-changing request
 * - sends the selected company for super admins
 * - routes 401 / 423 responses to the auth store
 */
/** Path the app is served from: '' at a domain root, '/HR-ADVANCED%20APP/public' under WAMP. */
export const basePath = window.APP_BASE || '';

const api = axios.create({
    baseURL: `${basePath}/api`,
    withCredentials: true,
    withXSRFToken: true,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
});

let csrfReady = false;
let handlers = { onUnauthenticated: null, onSecurityRequired: null, onRecordNotFound: null };

export function configureApi(h) {
    handlers = { ...handlers, ...h };
}

export async function ensureCsrf() {
    if (!csrfReady) {
        await axios.get(`${basePath}/sanctum/csrf-cookie`, { withCredentials: true });
        csrfReady = true;
    }
}

api.interceptors.request.use(async (config) => {
    if (['post', 'put', 'patch', 'delete'].includes(config.method)) {
        await ensureCsrf();
    }
    const company = localStorage.getItem('companyScope');
    if (company) config.headers['X-Company-Id'] = company;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    async (error) => {
        const status = error.response?.status;
        if (status === 419) {
            csrfReady = false; // CSRF token rotated (e.g. after login) — retry once
            if (!error.config.__retried) {
                error.config.__retried = true;
                await ensureCsrf();
                return api(error.config);
            }
        }
        // Server-side sign-outs (inactive company, idle / absolute session timeout) explain themselves on the login page.
        if (status === 401 && error.response.data?.code) {
            try { sessionStorage.setItem('authNotice', error.response.data.message); } catch { /* storage unavailable */ }
        }
        if (status === 401 && handlers.onUnauthenticated && !error.config.url.startsWith('/auth/login')) {
            handlers.onUnauthenticated();
        }
        if (status === 423 && handlers.onSecurityRequired && error.response.data?.code) {
            handlers.onSecurityRequired(error.response.data.code);
        }
        // A record page whose main record is missing (usually: it belongs to another company after a switch).
        if (status === 404 && (error.config.method ?? 'get') === 'get' && handlers.onRecordNotFound) {
            handlers.onRecordNotFound(error.config.url ?? '');
        }
        // Super admin viewing "All companies" on a page that works on one company: show the company
        // banner and treat reads as empty instead of failing the page.
        if (status === 422 && /select a company/i.test(error.response?.data?.message ?? '') && handlers.onCompanyRequired) {
            handlers.onCompanyRequired();
            if ((error.config.method ?? 'get') === 'get') {
                return { ...error.response, status: 200, data: { data: [], company_required: true } };
            }
        }
        return Promise.reject(error);
    },
);

/** Extracts Laravel validation errors into { field: 'first message' }. */
export function fieldErrors(error) {
    const errors = error?.response?.data?.errors || {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
}

export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
    const data = error?.response?.data;
    if (data?.errors) {
        // Summarise every failing field, not just the first, so users can fix them in one pass.
        const messages = [...new Set(Object.values(data.errors).map((v) => (Array.isArray(v) ? v[0] : v)).filter(Boolean))];
        if (!messages.length) return fallback;
        if (messages.length === 1) return messages[0];
        return `Please fix ${messages.length} issues: ${messages.slice(0, 4).join(' ')}${messages.length > 4 ? ` (+${messages.length - 4} more)` : ''}`;
    }
    return data?.message || fallback;
}

export default api;
