import http, { downloadBlob } from './http';

export const dbBackupService = {
    export: () => downloadBlob('/admin/db/export', {},
        `atlas-db-${new Date().toISOString().slice(0, 10)}.xlsx`),

    import: (file) => {
        const fd = new FormData();
        fd.append('archivo', file);
        return http.post('/admin/db/import', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }).then(r => r.data);
    },
};
