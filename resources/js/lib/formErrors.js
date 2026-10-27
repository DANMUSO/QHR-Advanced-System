import { ref, watch, onMounted } from 'vue';

/**
 * Field errors that clear themselves: when a form field changes, its server error (and any nested ones, e.g.
 * `next_of_kin.0.phone` when `next_of_kin` changes) disappears, so a corrected field never keeps a stale message.
 *
 *   const form = ref({...});
 *   const errors = useFormErrors(() => form.value);   // then: errors.value = fieldErrors(e)
 *
 * The watcher starts once the component is mounted, so `form` may be declared after this call.
 */
export function useFormErrors(source) {
    const errors = ref({});
    onMounted(() => {
        let previous = snapshot(source());
        watch(() => snapshot(source()), (next) => {
            const changed = Object.keys({ ...previous, ...next }).filter((k) => previous[k] !== next[k]);
            previous = next;
            if (!changed.length) return;
            const remaining = { ...errors.value };
            let cleared = false;
            for (const key of Object.keys(remaining)) {
                if (changed.some((c) => key === c || key.startsWith(`${c}.`))) {
                    delete remaining[key];
                    cleared = true;
                }
            }
            if (cleared) errors.value = remaining;
        });
    });
    return errors;
}

function snapshot(obj) {
    const out = {};
    for (const [k, v] of Object.entries(obj ?? {})) {
        out[k] = typeof File !== 'undefined' && v instanceof File ? `file:${v.name}:${v.size}:${v.lastModified}` : JSON.stringify(v);
    }
    return out;
}
