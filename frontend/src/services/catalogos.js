import http from './http';

/** Cliente CRUD genérico para un catálogo. Recibe slug del endpoint. */
export function catalogoService(slug) {
    const base = `/${slug}`;
    return {
        list:   (params) => http.get(base, { params }).then(r => r.data),
        get:    (id) => http.get(`${base}/${id}`).then(r => r.data),
        create: (data) => http.post(base, data).then(r => r.data),
        update: (id, data) => http.put(`${base}/${id}`, data).then(r => r.data),
        remove: (id) => http.delete(`${base}/${id}`).then(r => r.data),
    };
}

/**
 * Listado completo para selectores. El servidor entrega de a 200 como máximo:
 * se piden todas las páginas, para que no falte nadie en el combo.
 */
export async function listAll(slug, params = {}) {
    const filas = [];
    for (let page = 1; ; page++) {
        const res = await http.get(`/${slug}`, { params: { per_page: 200, ...params, page } });
        const datos = res.data?.data ?? res.data ?? [];
        filas.push(...datos);
        const ultima = res.data?.last_page ?? 1;
        if (!Array.isArray(res.data?.data) || page >= ultima) break;
    }
    return filas;
}
