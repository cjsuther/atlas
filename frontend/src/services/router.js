/**
 * Mini-router basado en hash. Las rutas se registran con register().
 * Cada ruta tiene un path (regex con :param) y un handler async.
 */

const routes = [];
let notFoundHandler = null;
let beforeEachHandler = null;

function compile(path) {
    const keys = [];
    const pattern = path.replace(/:(\w+)/g, (_, k) => {
        keys.push(k);
        return '([^/]+)';
    });
    return { regex: new RegExp(`^${pattern}$`), keys };
}

export const router = {
    register(path, handler) {
        routes.push({ path, handler, ...compile(path) });
        return this;
    },
    notFound(handler) { notFoundHandler = handler; return this; },
    beforeEach(handler) { beforeEachHandler = handler; return this; },

    navigate(path, replace = false) {
        const target = path.startsWith('#') ? path : `#${path}`;
        if (location.hash === target) {
            this.resolve();
            return;
        }
        if (replace) {
            history.replaceState(null, '', target);
            this.resolve();
        } else {
            location.hash = target;
        }
    },

    async resolve() {
        let hash = location.hash || '#/';
        if (hash.startsWith('#')) hash = hash.slice(1);
        if (!hash.startsWith('/')) hash = '/' + hash;

        const [pathPart, queryPart = ''] = hash.split('?');
        const params = {};
        const query = Object.fromEntries(new URLSearchParams(queryPart));

        let matched = null;
        for (const r of routes) {
            const m = pathPart.match(r.regex);
            if (m) {
                r.keys.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });
                matched = r;
                break;
            }
        }

        if (beforeEachHandler) {
            const cont = await beforeEachHandler({ path: pathPart, params, query, route: matched });
            if (cont === false) return;
        }

        if (!matched) {
            if (notFoundHandler) await notFoundHandler({ path: pathPart, params, query });
            return;
        }
        await matched.handler({ path: pathPart, params, query });
    },

    start() {
        window.addEventListener('hashchange', () => this.resolve());
        window.addEventListener('DOMContentLoaded', () => this.resolve());
        if (document.readyState !== 'loading') this.resolve();
    },
};
