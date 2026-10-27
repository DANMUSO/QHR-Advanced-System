import 'sweetalert2/dist/sweetalert2.min.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { configureApi } from './lib/api';
import { useAuthStore } from './stores/auth';
import { useUiStore } from './stores/ui';

const app = createApp(App);
// Base path for server links in templates (e.g. downloads): '' at a domain root, '/HR-ADVANCED%20APP/public' under WAMP.
app.config.globalProperties.$base = window.APP_BASE || '';
app.use(createPinia());

const auth = useAuthStore();
const ui = useUiStore();
ui.applyTheme();

configureApi({
    // The record behind the open page (its id is in the request URL) is not in the selected company: back to the list.
    onRecordNotFound(url) {
        const route = router.currentRoute.value;
        const ids = Object.entries(route.params ?? {}).filter(([k, v]) => k !== 'pathMatch' && v).map(([, v]) => String(v));
        const path = url.split('?')[0];
        if (ids.length && ids.some((id) => path.split('/').pop() === id)) ui.leaveForeignRecord(router);
    },
    onCompanyRequired() {
        ui.companyRequired = true;
    },
    onUnauthenticated() {
        if (auth.user) {
            auth.user = null;
            router.push({ name: 'login', query: { expired: 1 } });
        }
    },
    onSecurityRequired(code) {
        auth.securityBlock = code;
        router.push({ name: 'secure-account' });
    },
});

app.use(router);
app.mount('#app');
