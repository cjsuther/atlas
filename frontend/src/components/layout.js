import { auth, api } from '../services/api.js';
import { router } from '../services/router.js';
import { logoSvg } from './logo.js';
import { icons } from './icons.js';
import { toast } from '../services/toast.js';

const SIDEBAR_KEY = 'atlas_sidebar_collapsed';

const NAV = [
    { section: 'Principal', items: [
        { id: 'dashboard', label: 'Dashboard',    href: '#/dashboard',  icon: icons.dashboard, roles: ['admin','operador','consulta'] },
        { id: 'contratos', label: 'Contratos',    href: '#/contratos',  icon: icons.contratos, roles: ['admin','operador','consulta'] },
    ]},
    { section: 'Catálogos', items: [
        { id: 'estados',        label: 'Estados',          href: '#/catalogos/estados',        icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'tipos-contrato', label: 'Tipos de Contrato', href: '#/catalogos/tipos-contrato', icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'solicitantes',   label: 'Solicitantes',     href: '#/catalogos/solicitantes',   icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'sectores',       label: 'Sectores',         href: '#/catalogos/sectores',       icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'utt',            label: 'UTTs',             href: '#/catalogos/utt',            icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'uvt',            label: 'UVTs',             href: '#/catalogos/uvt',            icon: icons.catalogos, roles: ['admin','operador','consulta'] },
        { id: 'personal',       label: 'Personal',         href: '#/catalogos/personal',       icon: icons.catalogos, roles: ['admin','operador','consulta'] },
    ]},
    { section: 'Administración', items: [
        { id: 'usuarios', label: 'Roles de Usuario', href: '#/usuarios', icon: icons.users, roles: ['admin'] },
    ]},
];

let titleEl;

export function renderShell(activeNavId, pageTitle) {
    const app = document.getElementById('app');
    const user = auth.getUser() || { username: '?', display_name: '?', rol: 'consulta' };
    const collapsed = localStorage.getItem(SIDEBAR_KEY) === '1';

    app.innerHTML = `
        <div class="app-shell ${collapsed ? 'collapsed' : ''}" id="app-shell">
            <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
            <aside class="app-sidebar">
                <div class="brand">
                    ${logoSvg(28)}
                    <span class="name">ATLAS</span>
                </div>
                <nav>
                    ${NAV.map(sec => `
                        ${sec.items.some(i => i.roles.includes(user.rol)) ? `<div class="nav-section-title">${sec.section}</div>` : ''}
                        ${sec.items.filter(i => i.roles.includes(user.rol)).map(i => `
                            <a class="nav-item ${i.id === activeNavId ? 'active' : ''}" href="${i.href}">
                                ${i.icon}
                                <span class="label">${i.label}</span>
                            </a>
                        `).join('')}
                    `).join('')}
                </nav>
            </aside>

            <header class="app-header">
                <div style="display:flex;align-items:center;">
                    <button class="toggle-sidebar" id="btn-toggle" title="Colapsar menú">${icons.menu}</button>
                    <span class="title" id="page-title">${pageTitle || ''}</span>
                </div>
                <div class="user-area">
                    <div class="user-info">
                        <div class="name">${escape(user.display_name || user.username)}</div>
                        <div class="role">${user.rol}</div>
                    </div>
                    <button class="btn btn-ghost" id="btn-logout" title="Cerrar sesión">
                        ${icons.logout}
                    </button>
                </div>
            </header>

            <main class="app-main" id="app-main">
                <div class="empty-state"><span class="loader dark"></span> Cargando...</div>
            </main>
        </div>
    `;

    titleEl = document.getElementById('page-title');

    const isMobile = () => window.matchMedia('(max-width: 768px)').matches;
    const shell = document.getElementById('app-shell');

    document.getElementById('btn-toggle').addEventListener('click', () => {
        if (isMobile()) {
            shell.classList.toggle('mobile-open');
        } else {
            shell.classList.toggle('collapsed');
            localStorage.setItem(SIDEBAR_KEY, shell.classList.contains('collapsed') ? '1' : '0');
        }
    });

    // Cerrar drawer al tocar el backdrop
    document.getElementById('sidebar-backdrop').addEventListener('click', () => {
        shell.classList.remove('mobile-open');
    });

    // Cerrar drawer al navegar dentro del menú (sólo en mobile)
    shell.querySelectorAll('.app-sidebar .nav-item').forEach(item => {
        item.addEventListener('click', () => {
            if (isMobile()) shell.classList.remove('mobile-open');
        });
    });

    // Si el viewport pasa de mobile a desktop con el drawer abierto, lo cerramos
    window.addEventListener('resize', () => {
        if (!isMobile()) shell.classList.remove('mobile-open');
    });

    document.getElementById('btn-logout').addEventListener('click', async () => {
        try { await api.post('/auth/logout', {}); } catch (e) { /* ignorar */ }
        auth.clear();
        toast.info('Sesión cerrada.');
        router.navigate('/login');
    });

    return document.getElementById('app-main');
}

export function setPageTitle(t) {
    if (titleEl) titleEl.textContent = t || '';
}

function escape(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
