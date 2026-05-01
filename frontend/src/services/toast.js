/** Sistema de notificaciones toast simple. */
export function showToast(message, type = 'info', timeout = 3500) {
    const root = document.getElementById('toast-root');
    if (!root) return;
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = message;
    root.appendChild(el);
    setTimeout(() => {
        el.style.transition = 'opacity 0.25s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 260);
    }, timeout);
}

export const toast = {
    info:    (m, t) => showToast(m, 'info', t),
    success: (m, t) => showToast(m, 'success', t),
    error:   (m, t) => showToast(m, 'error', t),
};
