const ARS = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const INT = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 });

export function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '';
    const n = typeof v === 'number' ? v : Number(v);
    if (!isFinite(n)) return '';
    return ARS.format(n);
}

export function fmtInt(v) {
    if (v === null || v === undefined || v === '') return '';
    const n = typeof v === 'number' ? v : Number(v);
    if (!isFinite(n)) return '';
    return INT.format(n);
}

export function fmtDate(v) {
    if (!v) return '';
    const d = v instanceof Date ? v : new Date(v);
    if (isNaN(d.getTime())) return '';
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
}

export function fmtDateTime(v) {
    if (!v) return '';
    const d = v instanceof Date ? v : new Date(v);
    if (isNaN(d.getTime())) return '';
    const hh = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    return `${fmtDate(d)} ${hh}:${mi}`;
}

/** YYYY-MM-DD a partir de un Date, ISO o ya formateado. */
export function toIsoDate(v) {
    if (!v) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
    const m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (m) return `${m[3]}-${m[2]}-${m[1]}`;
    const d = new Date(v);
    if (!isNaN(d.getTime())) return d.toISOString().slice(0, 10);
    return v;
}

/** Devuelve clase de badge según nombre de estado (principal o ejecución). */
export function badgeForEstado(estado) {
    if (!estado) return 'badge-default';
    const name = (typeof estado === 'string' ? estado : estado.nombre || '').toLowerCase();
    if (name.includes('finaliz'))     return 'badge-finalizado';
    if (name.includes('ejecu'))       return 'badge-ejecucion';
    if (name.includes('firma'))       return 'badge-firma';
    if (name.includes('borrador'))    return 'badge-borrador';
    if (name.includes('jurídic') || name.includes('juridic')) return 'badge-info';
    if (name.includes('revisión') || name.includes('revision')) return 'badge-info';
    if (name.includes('firmado'))     return 'badge-success';
    if (name.includes('sin'))         return 'badge-sinefecto';
    return 'badge-default';
}

export function debounce(fn, ms = 300) {
    let to;
    return (...args) => {
        clearTimeout(to);
        to = setTimeout(() => fn(...args), ms);
    };
}
