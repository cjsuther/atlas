import { api } from '../../services/api.js';
import { renderShell, setPageTitle } from '../../components/layout.js';
import { fmtDate, badgeForEstado, escapeHtml } from '../../services/format.js';
import { toast } from '../../services/toast.js';

export async function renderDashboard() {
    const main = renderShell('dashboard', 'Dashboard');
    setPageTitle('Dashboard');

    main.innerHTML = '<div class="empty-state"><span class="loader dark"></span> Cargando KPIs...</div>';

    try {
        const [kpisRes, vencRes] = await Promise.all([
            api.get('/dashboard/kpis'),
            api.get('/dashboard/vencimientos'),
        ]);

        const kpis = kpisRes.data;
        const proximos = vencRes.data || [];

        const estadoCount = (idoNombre) => {
            const e = kpis.por_estado.find(x => x.estado_id === idoNombre || x.estado_nombre === idoNombre);
            return e ? e.total : 0;
        };

        main.innerHTML = `
            <h1 class="page-title">Panel de control</h1>
            <p class="page-subtitle">Resumen del estado de los contratos</p>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="label">Total contratos</div>
                    <div class="value">${kpis.total}</div>
                </div>
                <div class="kpi-card info">
                    <div class="label">En Tramitación</div>
                    <div class="value">${estadoCount(1)}</div>
                </div>
                <div class="kpi-card success">
                    <div class="label">En Ejecución</div>
                    <div class="value">${estadoCount(2)}</div>
                </div>
                <div class="kpi-card">
                    <div class="label">Finalizados</div>
                    <div class="value">${estadoCount(3)}</div>
                </div>
                <div class="kpi-card danger">
                    <div class="label">Sin efecto</div>
                    <div class="value">${estadoCount(4)}</div>
                </div>
            </div>

            <h3 class="page-title" style="font-size:16px;margin-top:18px;">Próximos vencimientos</h3>
            <div class="kpi-grid">
                <div class="kpi-card danger">
                    <div class="label">Vencidos</div>
                    <div class="value">${kpis.vencimientos.vencidos}</div>
                </div>
                <div class="kpi-card warning">
                    <div class="label">Próximos 30 días</div>
                    <div class="value">${kpis.vencimientos.dias_30}</div>
                </div>
                <div class="kpi-card warning">
                    <div class="label">Entre 31 y 60 días</div>
                    <div class="value">${kpis.vencimientos.dias_60}</div>
                </div>
                <div class="kpi-card info">
                    <div class="label">Entre 61 y 90 días</div>
                    <div class="value">${kpis.vencimientos.dias_90}</div>
                </div>
            </div>

            <div class="card" style="margin-top:18px;">
                <h3 style="margin-top:0;color:var(--color-primary);">10 contratos más próximos a vencer</h3>
                ${proximos.length === 0 ? `
                    <div class="empty-state">No hay contratos en ejecución próximos a vencer.</div>
                ` : `
                    <div class="table-wrapper">
                        <table class="atlas-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Proyecto</th>
                                    <th>Tipo</th>
                                    <th>Solicitante</th>
                                    <th>Sector</th>
                                    <th>Vencimiento</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${proximos.map(c => {
                                    const b = badgeForEstado(c.estado);
                                    return `
                                    <tr style="cursor:pointer;" onclick="location.hash='#/contratos/${c.id_cto}'">
                                        <td>${c.id_cto}</td>
                                        <td>${escapeHtml(c.nombre_proy)}</td>
                                        <td>${escapeHtml(c.tipo_contrato?.tipo || '')}</td>
                                        <td>${escapeHtml(c.solicitante?.razon_social || '')}</td>
                                        <td>${escapeHtml(c.sector?.nombre || '')}</td>
                                        <td>${fmtDate(c.fecha_vencimiento)}</td>
                                        <td><span class="badge ${b.cls}">${b.label}</span></td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>
        `;
    } catch (e) {
        toast.error('No se pudo cargar el dashboard.');
        main.innerHTML = `<div class="empty-state">Error al cargar el dashboard. ${escapeHtml(e.message || '')}</div>`;
    }
}
