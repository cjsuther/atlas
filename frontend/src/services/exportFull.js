import { downloadBlob } from './http';

export const exportFullService = {
    download: () => downloadBlob('/export/excel', {},
        `atlas-export-${new Date().toISOString().slice(0, 10)}.xlsx`),
};
