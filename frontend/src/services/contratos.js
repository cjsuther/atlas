import http, { downloadBlob } from './http';

/** Archivos adjuntos de un contrato (el nodo del tercer nivel de la estructura). */
export const contratoArchivosService = {
    list: (sectorId) =>
        http.get(`/contratos/${sectorId}/archivos`).then(r => r.data?.data ?? []),

    subir: (sectorId, archivo, descripcion) => {
        const fd = new FormData();
        fd.append('archivo', archivo);
        if (descripcion) fd.append('descripcion', descripcion);
        return http.post(`/contratos/${sectorId}/archivos`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }).then(r => r.data);
    },

    descargar: (archivo) =>
        downloadBlob(`/contrato-archivos/${archivo.id}/descargar`, {}, archivo.nombre_original),

    quitar: (id) => http.delete(`/contrato-archivos/${id}`).then(r => r.data),
};
