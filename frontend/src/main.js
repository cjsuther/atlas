import { router } from './services/router.js';
import { auth, api } from './services/api.js';
import { renderLogin } from './modules/login/login.js';
import { renderDashboard } from './modules/dashboard/dashboard.js';
import { renderContratosList } from './modules/contratos/contratosList.js';
import { renderContratoForm } from './modules/contratos/contratoForm.js';
import { renderContratoDetail } from './modules/contratos/contratoDetail.js';
import { renderEntidadAbm } from './modules/entidades/entidadAbm.js';
import { renderRoles } from './modules/roles/roles.js';

router
    .register('/login', renderLogin)
    .register('/dashboard', renderDashboard)
    .register('/contratos', renderContratosList)
    .register('/contratos/nuevo', renderContratoForm)
    .register('/contratos/:id', renderContratoDetail)
    .register('/contratos/:id/editar', renderContratoForm)
    .register('/catalogos/:slug', renderEntidadAbm)
    .register('/usuarios', renderRoles)
    .register('/', () => router.navigate(auth.isAuthenticated() ? '/dashboard' : '/login'))
    .notFound(() => {
        document.getElementById('app').innerHTML = `
            <div class="empty-state">
                <h2>404 — Página no encontrada</h2>
                <p>La ruta solicitada no existe.</p>
                <a href="#/dashboard">Volver al dashboard</a>
            </div>`;
    })
    .beforeEach(async ({ path }) => {
        // Redirigir a login si no hay token y la ruta no es /login
        if (path !== '/login' && !auth.isAuthenticated()) {
            router.navigate('/login', true);
            return false;
        }
        // Si ya está logueado y va a /login, redirigir a dashboard
        if (path === '/login' && auth.isAuthenticated()) {
            router.navigate('/dashboard', true);
            return false;
        }

        // Validar token con backend (refresca user)
        if (path !== '/login' && auth.isAuthenticated()) {
            try {
                const me = await api.get('/auth/me');
                if (me && me.user) auth.setUser(me.user);
            } catch (e) {
                // 401 ya redirige al login
            }
        }
        return true;
    });

router.start();
