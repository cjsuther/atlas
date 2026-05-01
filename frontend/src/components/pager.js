/**
 * Componente de paginación. Renderiza dentro del contenedor dado y
 * llama onChange(page) cuando se cambia de página.
 */
export function renderPager(container, paginator, onChange) {
    if (!paginator) { container.innerHTML = ''; return; }
    const { current_page, last_page, total, from, to } = paginator;

    const pages = [];
    const max = 5;
    let start = Math.max(1, current_page - Math.floor(max / 2));
    let end = Math.min(last_page, start + max - 1);
    if (end - start + 1 < max) start = Math.max(1, end - max + 1);

    for (let i = start; i <= end; i++) pages.push(i);

    container.innerHTML = `
        <div class="pager">
            <div class="info">
                ${total ? `Mostrando ${from || 0}–${to || 0} de ${total}` : 'Sin resultados'}
            </div>
            <div class="pages">
                <button data-p="${current_page - 1}" ${current_page <= 1 ? 'disabled' : ''}>‹</button>
                ${pages.map(p => `<button data-p="${p}" class="${p === current_page ? 'active' : ''}">${p}</button>`).join('')}
                <button data-p="${current_page + 1}" ${current_page >= last_page ? 'disabled' : ''}>›</button>
            </div>
        </div>
    `;
    container.querySelectorAll('button[data-p]').forEach(b => {
        b.addEventListener('click', () => {
            const p = Number(b.dataset.p);
            if (p >= 1 && p <= last_page && p !== current_page) onChange(p);
        });
    });
}
