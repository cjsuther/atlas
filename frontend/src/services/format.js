/**
 * Formateadores y helpers de UI.
 */

const ARS = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '';
    const n = typeof v === 'number' ? v : Number(v);
    if (!isFinite(n)) return '';
    return ARS.format(n);
}

/** ISO o Date → DD/MM/YYYY */
export function fmtDate(v) {
    if (!v) return '';
    const d = v instanceof Date ? v : new Date(v);
    if (isNaN(d.getTime())) return '';
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
}

/** ISO datetime → DD/MM/YYYY HH:MM */
export function fmtDateTime(v) {
    if (!v) return '';
    const d = v instanceof Date ? v : new Date(v);
    if (isNaN(d.getTime())) return '';
    const date = fmtDate(d);
    const hh = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    return `${date} ${hh}:${mi}`;
}

export function badgeForEstado(estado) {
    if (!estado) return { cls: 'badge-default', label: '—' };
    const name = (estado.estado_nombre || estado).toLowerCase();
    if (name.includes('tramita'))  return { cls: 'badge-tramitacion', label: estado.estado_nombre || estado };
    if (name.includes('ejecu'))    return { cls: 'badge-ejecucion',   label: estado.estado_nombre || estado };
    if (name.includes('finaliz'))  return { cls: 'badge-finalizado',  label: estado.estado_nombre || estado };
    if (name.includes('sin'))      return { cls: 'badge-sinefecto',   label: estado.estado_nombre || estado };
    return { cls: 'badge-default', label: estado.estado_nombre || estado };
}

export function debounce(fn, ms = 300) {
    let to;
    return (...args) => {
        clearTimeout(to);
        to = setTimeout(() => fn(...args), ms);
    };
}

export function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** Convierte input DD/MM/YYYY a YYYY-MM-DD para la API. */
export function inputDateToIso(v) {
    if (!v) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
    const m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (m) return `${m[3]}-${m[2]}-${m[1]}`;
    return v;
}
