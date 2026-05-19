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

/** Listado liviano para selectores: trae hasta 500 items. */
export async function listAll(slug, params = {}) {
    const res = await http.get(`/${slug}`, { params: { per_page: 500, ...params } });
    return res.data?.data ?? res.data ?? [];
}
