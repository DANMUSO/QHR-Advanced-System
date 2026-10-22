import api, { errorMessage } from '@/lib/api';
import swal from '@/lib/swal';

/** Re-sends the account activation email. Returns true on success. */
export async function resendActivation(user) {
    try {
        const { data } = await api.post(`/admin/users/${user.id}/resend-activation`);
        swal.toast(data.message);
        return true;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
        return false;
    }
}

/**
 * Local development only (the API refuses elsewhere): issues a fresh activation link and copies it,
 * so testers can activate accounts without a mailbox. Falls back to a prompt when the clipboard is blocked.
 */
export async function copyActivationLink(user) {
    try {
        const { data } = await api.post(`/admin/users/${user.id}/activation-link`);
        try {
            await navigator.clipboard.writeText(data.url);
            swal.toast('Activation link copied. Open it in a private window to activate the account.');
        } catch {
            window.prompt('Copy this activation link:', data.url);
        }
        return data.url;
    } catch (e) {
        swal.toast(errorMessage(e), 'error');
        return null;
    }
}
