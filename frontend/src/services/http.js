import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import router from '@/router';

const http = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        // Evita la página intersticial cuando se accede vía túnel ngrok
        'ngrok-skip-browser-warning': 'true',
    },
});

http.interceptors.request.use((config) => {
    const auth = useAuthStore();
    if (auth.token) {
        config.headers.Authorization = `Bearer ${auth.token}`;
    }
    return config;
});

http.interceptors.response.use(
    (res) => res,
    (err) => {
        if (err.response?.status === 401) {
            const auth = useAuthStore();
            auth.clear();
            if (router.currentRoute.value.name !== 'login') {
                router.replace({ name: 'login' });
            }
        }
        return Promise.reject(err);
    },
);

/** Helper para descargar archivos (Excel) con el token aplicado. */
export async function downloadBlob(path, params = {}, fallbackName = 'download.xlsx') {
    const res = await http.get(path, { params, responseType: 'blob' });
    const cd = res.headers['content-disposition'] || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : fallbackName;

    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        URL.revokeObjectURL(url);
        a.remove();
    }, 100);
}

/** Extrae un mensaje de error legible desde un AxiosError. */
export function extractError(err, fallback = 'Error inesperado.') {
    const data = err?.response?.data;
    if (data?.message) return data.message;
    if (data?.error)   return data.error;
    if (data?.errors) {
        const first = Object.values(data.errors).flat()[0];
        if (first) return first;
    }
    return err?.message || fallback;
}

export default http;
