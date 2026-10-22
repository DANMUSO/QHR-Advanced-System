import Swal from 'sweetalert2';

const base = Swal.mixin({
    customClass: {
        popup: 'swal-popup',
        confirmButton: 'swal-btn-confirm',
        cancelButton: 'swal-btn-cancel',
    },
    buttonsStyling: false,
    reverseButtons: true,
});

export function toast(message, type = 'success') {
    base.fire({
        toast: true,
        position: 'top-end',
        icon: type === 'error' ? 'error' : type === 'warning' ? 'warning' : type === 'info' ? 'info' : 'success',
        title: message,
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
    });
}

export function confirm({ title = 'Are you sure?', text = '', confirmLabel = 'Delete', danger = true } = {}) {
    return base.fire({
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirmLabel,
        cancelButtonText: 'Cancel',
    }).then((r) => r.isConfirmed);
}

export default { toast, confirm };
