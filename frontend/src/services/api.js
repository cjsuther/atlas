/**
 * ATLAS — Cliente HTTP centralizado para la API.
 * Maneja autenticación por token Sanctum y errores estructurados.
 */

const STORAGE_KEY = 'atlas_token';
const USER_KEY = 'atlas_user';

export const auth = {
    getToken() {
        return localStorage.getItem(STORAGE_KEY);
    },
    setToken(token) {
        if (token) localStorage.setItem(STORAGE_KEY, token);
        else localStorage.removeItem(STORAGE_KEY);
    },
    getUser() {
        const raw = localStorage.getItem(USER_KEY);
        return raw ? JSON.parse(raw) : null;
    },
    setUser(user) {
        if (user) localStorage.setItem(USER_KEY, JSON.stringify(user));
        else localStorage.removeItem(USER_KEY);
    },
    isAuthenticated() {
        return !!this.getToken();
    },
    clear() {
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(USER_KEY);
    },
    hasRole(...roles) {
        const u = this.getUser();
        return u && roles.includes(u.rol);
    },
};

const BASE_URL = '/api';

async function request(path, { method = 'GET', body, headers = {}, raw = false } = {}) {
    const token = auth.getToken();

    const opts = {
        method,
        headers: {
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...headers,
        },
    };

    if (body !== undefined && body !== null) {
        if (body instanceof FormData) {
            opts.body = body;
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
    }

    const res = await fetch(BASE_URL + path, opts);

    if (raw) return res;

    if (res.status === 401) {
        auth.clear();
        if (location.hash !== '#/login') location.hash = '#/login';
        const err = new Error('Sesión expirada o inválida.');
        err.status = 401;
        throw err;
    }

    const contentType = res.headers.get('content-type') || '';
    let data = null;
    if (contentType.includes('application/json')) {
        data = await res.json().catch(() => null);
    }

    if (!res.ok) {
        const err = new Error((data && (data.message || data.error)) || `HTTP ${res.status}`);
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

export const api = {
    get:  (path, params) => {
        const qs = params ? '?' + new URLSearchParams(
            Object.entries(params).filter(([_, v]) => v !== '' && v !== null && v !== undefined)
        ).toString() : '';
        return request(path + qs);
    },
    post:   (path, body) => request(path, { method: 'POST',   body }),
    put:    (path, body) => request(path, { method: 'PUT',    body }),
    del:    (path)       => request(path, { method: 'DELETE' }),
    raw:    (path, opts) => request(path, { ...opts, raw: true }),
};

export async function downloadFile(path, params, suggestedName = 'download') {
    const qs = params ? '?' + new URLSearchParams(
        Object.entries(params).filter(([_, v]) => v !== '' && v !== null && v !== undefined)
    ).toString() : '';
    const res = await api.raw(path + qs);
    if (!res.ok) {
        throw new Error(`Error en la descarga (HTTP ${res.status}).`);
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : suggestedName;

    const url = URL.createObjectURL(blob);
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
