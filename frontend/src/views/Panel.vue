<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Panel de Control</h1>
                <p class="page-subtitle">Indicadores y distribución de contratos</p>
            </div>
            <div>
                <button class="btn btn-secondary" :disabled="exporting" @click="exportarTodo">
                    <span v-if="exporting" class="loader dark" />
                    <IconLib v-else name="download" />
                    {{ exporting ? 'Generando…' : 'Exportar todo a Excel' }}
                </button>
            </div>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Desde</label>
                    <input v-model="filters.desde" type="date" class="input" />
                </div>
                <div class="field">
                    <label>Hasta</label>
                    <input v-model="filters.hasta" type="date" class="input" />
                </div>
                <div class="field">
                    <label>Moneda base</label>
                    <select v-model="filters.moneda_base" class="select">
                        <option value="Peso">Peso</option>
                        <option value="Dólar">Dólar</option>
                        <option value="Euro">Euro</option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" @click="clearFilters">Limpiar</button>
                <button class="btn btn-primary" @click="loadAll">Aplicar</button>
            </div>
        </div>

        <!-- Sección A — Indicadores principales -->
        <h3 style="margin:18px 0 10px;">Indicadores principales</h3>
        <div class="kpi-grid">
            <div class="kpi-card info">
                <div class="label">Contratos Principales</div>
                <div class="value">{{ fmtInt(ind?.totales?.contratos_principal) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Contratos de Ejecución</div>
                <div class="value">{{ fmtInt(ind?.totales?.contratos_ejecucion) }}</div>
            </div>
            <div class="kpi-card warning">
                <div class="label">En firma</div>
                <div class="value">{{ fmtInt(ind?.totales?.en_firma) }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">En ejecución</div>
                <div class="value">{{ fmtInt(ind?.totales?.en_ejecucion) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">Finalizados</div>
                <div class="value">{{ fmtInt(ind?.totales?.finalizados) }}</div>
            </div>
            <div class="kpi-card danger">
                <div class="label">Vencidos</div>
                <div class="value">{{ fmtInt(ind?.totales?.vencidos) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">Presup. ingresos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value">{{ fmtMoney(ind?.montos?.presupuestado_ingresos_total) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">Presup. gastos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value">{{ fmtMoney(ind?.montos?.presupuestado_gastos_total) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Ejec. ingresos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value">{{ fmtMoney(ind?.montos?.ejecutado_ingresos_total) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Ejec. gastos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value">{{ fmtMoney(ind?.montos?.ejecutado_gastos_total) }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">Beneficio (ejec. ing. − gtos.)</div>
                <div class="value">{{ fmtMoney(ind?.montos?.beneficio_total) }}</div>
            </div>
        </div>

        <!-- Sección C — Indicadores calculados -->
        <h3 style="margin:24px 0 10px;">Indicadores calculados</h3>
        <div class="kpi-grid">
            <div class="kpi-card info">
                <div class="label">Días promedio de firma</div>
                <div class="value">{{ calc?.dias_firma_promedio ?? '—' }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Días promedio de ejecución</div>
                <div class="value">{{ calc?.dias_ejecucion_promedio ?? '—' }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">% Finalizados en término (Principal)</div>
                <div class="value">{{ pctOrDash(calc?.porcentaje_finalizados_en_termino_principal) }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">% Finalizados en término (Ejecución)</div>
                <div class="value">{{ pctOrDash(calc?.porcentaje_finalizados_en_termino_ejecucion) }}</div>
            </div>
            <div class="kpi-card danger">
                <div class="label">% Vencidos sin cierre</div>
                <div class="value">{{ pctOrDash(calc?.porcentaje_vencidos_sin_cierre) }}</div>
            </div>
            <div class="kpi-card warning">
                <div class="label">% Ejecución económica</div>
                <div class="value">{{ pctOrDash(calc?.porcentaje_ejecucion_economica) }}</div>
            </div>
        </div>

        <!-- Sección B — Distribución -->
        <h3 style="margin:24px 0 10px;">Distribución</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por UVT — Principal</h4>
                <BarChart :rows="rowsPorUvtPrincipal" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por UVT — Ejecución</h4>
                <BarChart :rows="rowsPorUvtEjecucion" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Gerencia — Principal</h4>
                <BarChart :rows="rowsPorGerenciaPrincipal" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Tipo — Principal</h4>
                <BarChart :rows="rowsPorTipoPrincipal" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Tipo — Ejecución</h4>
                <BarChart :rows="rowsPorTipoEjecucion" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Estado — Principal</h4>
                <BarChart :rows="rowsPorEstadoPrincipal" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Estado — Ejecución</h4>
                <BarChart :rows="rowsPorEstadoEjecucion" />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Por Moneda — Principal</h4>
                <BarChart :rows="rowsPorMonedaPrincipal" />
            </div>
        </div>

        <!-- Vencimientos -->
        <h3 style="margin:24px 0 10px;">Próximos vencimientos</h3>
        <div class="kpi-grid">
            <div class="kpi-card danger">
                <div class="label">Vencidos · Principal</div>
                <div class="value">{{ fmtInt(venc?.vencidos?.principal) }}</div>
            </div>
            <div class="kpi-card danger">
                <div class="label">Vencidos · Ejecución</div>
                <div class="value">{{ fmtInt(venc?.vencidos?.ejecucion) }}</div>
            </div>
            <div class="kpi-card warning">
                <div class="label">≤ 30 días · Principal</div>
                <div class="value">{{ fmtInt(venc?.dias_30?.principal) }}</div>
            </div>
            <div class="kpi-card warning">
                <div class="label">≤ 30 días · Ejecución</div>
                <div class="value">{{ fmtInt(venc?.dias_30?.ejecucion) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">31–60 días · Principal</div>
                <div class="value">{{ fmtInt(venc?.dias_60?.principal) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">31–60 días · Ejecución</div>
                <div class="value">{{ fmtInt(venc?.dias_60?.ejecucion) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">61–90 días · Principal</div>
                <div class="value">{{ fmtInt(venc?.dias_90?.principal) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">61–90 días · Ejecución</div>
                <div class="value">{{ fmtInt(venc?.dias_90?.ejecucion) }}</div>
            </div>
        </div>

        <!-- Sección D — Rankings -->
        <h3 style="margin:24px 0 10px;">Rankings</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Gerencias con más contratos</h4>
                <table class="atlas-table">
                    <thead><tr><th>Gerencia</th><th style="text-align:right;">Cantidad</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in ranks?.gerencias_por_cantidad || []" :key="i">
                            <td>{{ r.gerencia || '—' }}</td>
                            <td style="text-align:right;">{{ fmtInt(r.cantidad) }}</td>
                        </tr>
                        <tr v-if="!ranks?.gerencias_por_cantidad?.length">
                            <td colspan="2" class="empty-state">Sin datos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">UVT por cantidad</h4>
                <table class="atlas-table">
                    <thead><tr><th>UVT</th><th style="text-align:right;">Cantidad</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in ranks?.uvt_por_cantidad || []" :key="i">
                            <td>{{ r.siglas }} — {{ r.nombre || '—' }}</td>
                            <td style="text-align:right;">{{ fmtInt(r.cantidad) }}</td>
                        </tr>
                        <tr v-if="!ranks?.uvt_por_cantidad?.length">
                            <td colspan="2" class="empty-state">Sin datos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">UVT por monto ejecutado ({{ ranks?.moneda_base }})</h4>
                <table class="atlas-table">
                    <thead>
                        <tr>
                            <th>UVT</th>
                            <th style="text-align:right;">Presupuestado</th>
                            <th style="text-align:right;">Ejecutado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in ranks?.uvt_por_monto || []" :key="i">
                            <td>{{ r.siglas }} — {{ r.nombre || '—' }}</td>
                            <td style="text-align:right;">{{ fmtMoney(r.presupuestado) }}</td>
                            <td style="text-align:right;">{{ fmtMoney(r.ejecutado) }}</td>
                        </tr>
                        <tr v-if="!ranks?.uvt_por_monto?.length">
                            <td colspan="3" class="empty-state">Sin datos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Actualizando datos…</div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { panelService } from '@/services/panel';
import { exportFullService } from '@/services/exportFull';
import { fmtInt, fmtMoney } from '@/composables/useFormat';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import BarChart from '@/components/BarChart.vue';
import IconLib from '@/components/IconLib.vue';

const toast = useToast();

const filters = reactive({
    desde: '',
    hasta: '',
    moneda_base: 'Peso',
});

const ind = ref(null);
const calc = ref(null);
const dUvt = ref(null);
const dGer = ref(null);
const dTipo = ref(null);
const dEstado = ref(null);
const dMoneda = ref(null);
const venc = ref(null);
const ranks = ref(null);
const loading = ref(false);
const exporting = ref(false);

async function exportarTodo() {
    exporting.value = true;
    try {
        await exportFullService.download();
        toast.success('Exportación generada.');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo generar el export.'));
    } finally {
        exporting.value = false;
    }
}

function paramsClean() {
    const p = {};
    if (filters.desde) p.desde = filters.desde;
    if (filters.hasta) p.hasta = filters.hasta;
    if (filters.moneda_base) p.moneda_base = filters.moneda_base;
    return p;
}

async function loadAll() {
    loading.value = true;
    try {
        const p = paramsClean();
        const [a, b, c, d, e, f, g, h, i] = await Promise.all([
            panelService.indicadores(p),
            panelService.calculados(p),
            panelService.porUvt(p),
            panelService.porGerencia(p),
            panelService.porTipo(p),
            panelService.porEstado(p),
            panelService.porMoneda(p),
            panelService.vencimientos(p),
            panelService.rankings(p),
        ]);
        ind.value = a; calc.value = b; dUvt.value = c; dGer.value = d;
        dTipo.value = e; dEstado.value = f; dMoneda.value = g;
        venc.value = h; ranks.value = i;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los indicadores.'));
    } finally {
        loading.value = false;
    }
}

function clearFilters() {
    filters.desde = ''; filters.hasta = ''; filters.moneda_base = 'Peso';
    loadAll();
}

function pctOrDash(v) {
    if (v === null || v === undefined) return '—';
    return `${v}%`;
}

const rowsPorUvtPrincipal  = computed(() => (dUvt.value?.contratos_principal || []).map(r => ({ label: r.siglas || '—', value: r.cantidad })));
const rowsPorUvtEjecucion  = computed(() => (dUvt.value?.contratos_ejecucion || []).map(r => ({ label: r.siglas || '—', value: r.cantidad })));
const rowsPorGerenciaPrincipal = computed(() => (dGer.value?.contratos_principal || []).map(r => ({ label: r.gerencia || '—', value: Number(r.cantidad) })));
const rowsPorTipoPrincipal = computed(() => (dTipo.value?.contratos_principal || []).map(r => ({ label: r.sigla || '—', value: r.cantidad })));
const rowsPorTipoEjecucion = computed(() => (dTipo.value?.contratos_ejecucion || []).map(r => ({ label: r.sigla || '—', value: r.cantidad })));
const rowsPorEstadoPrincipal = computed(() => (dEstado.value?.contratos_principal || []).map(r => ({ label: r.nombre || '—', value: r.cantidad })));
const rowsPorEstadoEjecucion = computed(() => (dEstado.value?.contratos_ejecucion || []).map(r => ({ label: r.nombre || '—', value: r.cantidad })));
const rowsPorMonedaPrincipal = computed(() => (dMoneda.value?.contratos_principal || []).map(r => ({ label: r.moneda || '—', value: Number(r.cantidad) })));

onMounted(loadAll);
</script>
