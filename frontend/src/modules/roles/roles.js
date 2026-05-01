import { api, auth } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { renderPager } from '../../components/pager.js';
import { toast } from '../../services/toast.js';
import { fmtDateTime, debounce, escapeHtml } from '../../services/format.js';

export async function renderRoles() {
    const main = renderShell('usuarios', 'Gestión de roles');
    setPageTitle('Gestión de Roles de Usuario');

    if (!auth.hasRole('admin')) {
        main.innerHTML = '<div class="empty-state">Acceso restringido a administradores.</div>';
        return;
    }

    let state = { page: 1, per_page: 20, search: '', rol: '' };
    const me = auth.getUser();

    main.innerHTML = `
        <h1 class="page-title">Roles de usuario</h1>
        <p class="page-subtitle">Usuarios que iniciaron sesión en ATLAS al menos una vez</p>

        <div class="toolbar">
            <div class="search-box">
                <input type="text" class="input" id="search" placeholder="Buscar por usuario, nombre, email...">
            </div>
            <div>
                <select class="select" id="filter-rol">
                    <option value="">Todos los roles</option>
                    <option value="admin">admin</option>
                    <option value="operador">operador</option>
                    <option value="consulta">consulta</option>
                </select>
            </div>
        </div>

        <div id="table-area"><div class="empty-state"><span class="loader dark"></span> Cargando...</div></div>
        <div id="pager"></div>
    `;

    document.getElementById('search').addEventListener('input', debounce(e => {
        state.search = e.target.value; state.page = 1; load();
    }, 300));
    document.getElementById('filter-rol').addEventListener('change', e => {
        state.rol = e.target.value; state.page = 1; load();
    });

    async function load() {
        const tableArea = document.getElementById('table-area');
        tableArea.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando...</div>';
        try {
            const data = await api.get('/usuarios', state);
            renderTable(tableArea, data, me, load);
            renderPager(document.getElementById('pager'), data, p => { state.page = p; load(); });
        } catch (e) {
            tableArea.innerHTML = `<div class="empty-state">Error: ${escapeHtml(e.message)}</div>`;
        }
    }

    load();
}

function renderTable(container, paginator, me, reload) {
    const items = paginator.data || [];
    if (items.length === 0) {
        container.innerHTML = '<div class="empty-state">Sin usuarios registrados.</div>';
        return;
    }

    container.innerHTML = `
        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>E-mail</th>
                        <th>Rol</th>
                        <th>Activo</th>
                        <th>Último login</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(u => {
                        const isMe = me && me.username === u.username;
                        return `
                        <tr>
                            <td><strong>${escapeHtml(u.username)}</strong>${isMe ? ' <span class="badge badge-default">tú</span>' : ''}</td>
                            <td>${escapeHtml(u.display_name || '')}</td>
                            <td>${escapeHtml(u.email || '')}</td>
                            <td>
                                <select class="select" data-username="${escapeHtml(u.username)}" data-attr="rol" ${isMe ? 'disabled title="No puede cambiar su propio rol"' : ''}>
                                    <option value="admin"    ${u.rol==='admin'    ?'selected':''}>admin</option>
                                    <option value="operador" ${u.rol==='operador' ?'selected':''}>operador</option>
                                    <option value="consulta" ${u.rol==='consulta' ?'selected':''}>consulta</option>
                                </select>
                            </td>
                            <td>
                                <select class="select" data-username="${escapeHtml(u.username)}" data-attr="activo" ${isMe ? 'disabled' : ''}>
                                    <option value="1" ${u.activo ? 'selected' : ''}>Sí</option>
                                    <option value="0" ${!u.activo ? 'selected' : ''}>No</option>
                                </select>
                            </td>
                            <td>${u.last_login ? fmtDateTime(u.last_login) : '—'}</td>
                        </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;

    container.querySelectorAll('select[data-username]').forEach(sel => {
        sel.addEventListener('change', async () => {
            const username = sel.dataset.username;
            const attr = sel.dataset.attr;
            const value = attr === 'activo' ? sel.value === '1' : sel.value;
            try {
                await api.put(`/usuarios/${encodeURIComponent(username)}`, { [attr]: value });
                toast.success(`Usuario ${username} actualizado.`);
            } catch (e) {
                toast.error(e.message || 'Error al actualizar.');
                reload();
            }
        });
    });
}
