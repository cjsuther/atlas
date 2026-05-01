/**
 * Modal genérico. Devuelve una promesa que resuelve cuando se cierra
 * (con el valor pasado a close()) o se descarta.
 */
export function openModal({ title, body, footer, large = false, onClose, escClose = true }) {
    const root = document.getElementById('modal-root');
    const wrap = document.createElement('div');
    wrap.className = 'modal-backdrop';
    wrap.innerHTML = `
        <div class="modal ${large ? 'large' : ''}" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h3>${title}</h3>
                <button class="close" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    `;
    const bodyEl   = wrap.querySelector('.modal-body');
    const footerEl = wrap.querySelector('.modal-footer');
    if (typeof body === 'string')   bodyEl.innerHTML = body;
    else if (body instanceof Node)  bodyEl.appendChild(body);

    if (typeof footer === 'string')  footerEl.innerHTML = footer;
    else if (footer instanceof Node) footerEl.appendChild(footer);
    else footerEl.style.display = 'none';

    root.appendChild(wrap);

    let resolveFn;
    const promise = new Promise(r => { resolveFn = r; });

    function close(value) {
        wrap.remove();
        if (onClose) onClose(value);
        resolveFn(value);
    }

    wrap.querySelector('.close').addEventListener('click', () => close(undefined));
    wrap.addEventListener('click', e => { if (e.target === wrap) close(undefined); });
    if (escClose) {
        const onKey = (e) => { if (e.key === 'Escape') { close(undefined); window.removeEventListener('keydown', onKey); } };
        window.addEventListener('keydown', onKey);
    }

    return { close, promise, bodyEl, footerEl, root: wrap };
}

/** Confirmación rápida. */
export async function confirmDialog({ title = 'Confirmar', message = '', confirmText = 'Aceptar', cancelText = 'Cancelar', danger = false } = {}) {
    return new Promise(resolve => {
        const m = openModal({
            title,
            body: `<p>${message}</p>`,
        });

        const btnCancel = document.createElement('button');
        btnCancel.className = 'btn btn-secondary';
        btnCancel.textContent = cancelText;
        btnCancel.onclick = () => { m.close(false); resolve(false); };

        const btnOk = document.createElement('button');
        btnOk.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
        btnOk.textContent = confirmText;
        btnOk.onclick = () => { m.close(true); resolve(true); };

        m.footerEl.appendChild(btnCancel);
        m.footerEl.appendChild(btnOk);
        m.footerEl.style.display = '';
    });
}
