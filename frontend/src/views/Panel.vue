<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Panel de Control</h1>
                <p class="page-subtitle">
                    Indicadores, saldos y distribución de contratos
                    <template v-if="alcance"> · {{ alcance }}</template>
                </p>
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
                <div class="field">
                    <label>Gerencia de Área</label>
                    <select v-model="filters.gerencia_area_id" class="select">
                        <option value="">Todas</option>
                        <option v-for="a in areas" :key="a.sector_id" :value="a.sector_id">{{ a.nombre }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>Subsector</label>
                    <select v-model="filters.sector_id" class="select">
                        <option value="">Todos</option>
                        <option v-for="g in subsectoresFiltrados" :key="g.sector_id" :value="g.sector_id">
                            {{ g.nombre }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" @click="clearFilters">Limpiar</button>
                <button class="btn btn-primary" @click="loadAll">Aplicar</button>
            </div>
        </div>

        <!-- Saldos: el usuario elige con qué agrupación verlos -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:22px 0 10px;">
            <h3 style="margin:0;">Saldos</h3>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:13px;color:var(--color-muted);">Ver saldos:</span>
                <button v-for="a in AGRUPACIONES" :key="a.value"
                        type="button"
                        :class="['btn', 'btn-sm', agrupacion === a.value ? 'btn-primary' : 'btn-secondary']"
                        @click="setAgrupacion(a.value)">
                    {{ a.label }}
                </button>
                <button type="button" class="btn btn-ghost btn-sm" :disabled="guardandoPref"
                        title="Usar esta agrupación cada vez que ingrese"
                        @click="guardarPreferencia">
                    {{ guardandoPref ? 'Guardando…' : 'Fijar como predeterminada' }}
                </button>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>{{ tituloColumnaSaldos }}</th>
                        <th style="text-align:right;">Contratos</th>
                        <th style="text-align:right;">Saldo inicial</th>
                        <th style="text-align:right;">Ingresos</th>
                        <th style="text-align:right;">Gastos</th>
                        <th style="text-align:right;">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in saldos?.filas || []" :key="f.clave"
                        :class="['saldo-row', `nivel-${f.nivel}`]">
                        <td>
                            <div :style="{ paddingLeft: `${f.nivel * 20}px` }">
                                <span v-if="f.nivel > 0" class="rama">└</span>
                                <span :class="{ raiz: f.nivel === 0 }">{{ f.etiqueta }}</span>
                            </div>
                            <div v-if="f.detalle"
                                 :style="{ paddingLeft: `${f.nivel * 20 + (f.nivel > 0 ? 14 : 0)}px` }"
                                 style="font-size:11px;color:var(--color-muted);">{{ f.detalle }}</div>
                        </td>
                        <td style="text-align:right;">{{ fmtInt(f.contratos) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(f.saldo_inicial) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(f.ejecutado_ingresos) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(f.ejecutado_gastos) }}</td>
                        <td style="text-align:right;font-weight:600;"
                            :style="{ color: f.saldo < 0 ? 'var(--color-danger)' : 'inherit' }">
                            {{ fmtMoney(f.saldo) }}
                        </td>
                    </tr>
                    <tr v-if="!saldos?.filas?.length">
                        <td colspan="6" class="empty-state">Sin datos para el filtro aplicado.</td>
                    </tr>
                    <tr v-else style="font-weight:600;border-top:2px solid var(--color-border);">
                        <td>Total ({{ saldos?.moneda_base }})</td>
                        <td style="text-align:right;">{{ fmtInt(saldos?.totales?.contratos) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(saldos?.totales?.saldo_inicial) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(saldos?.totales?.ejecutado_ingresos) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(saldos?.totales?.ejecutado_gastos) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(saldos?.totales?.saldo) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Indicadores principales -->
        <h3 style="margin:24px 0 10px;">Indicadores principales</h3>
        <div class="kpi-grid">
            <div class="kpi-card info">
                <div class="label">Contratos</div>
                <div class="value">{{ fmtInt(ind?.totales?.contratos) }}</div>
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
                <div class="label">Saldo inicial ({{ ind?.montos?.moneda_base }})</div>
                <div class="value money">{{ fmtMoney(ind?.montos?.saldo_inicial_total) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Ejec. ingresos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value money">{{ fmtMoney(ind?.montos?.ejecutado_ingresos_total) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Ejec. gastos ({{ ind?.montos?.moneda_base }})</div>
                <div class="value money">{{ fmtMoney(ind?.montos?.ejecutado_gastos_total) }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">Beneficio (ing. − gtos.)</div>
                <div class="value money">{{ fmtMoney(ind?.montos?.beneficio_total) }}</div>
            </div>
            <div class="kpi-card success">
                <div class="label">Saldo ({{ ind?.montos?.moneda_base }})</div>
                <div class="value money">{{ fmtMoney(ind?.montos?.saldo_total) }}</div>
            </div>
        </div>

        <!-- Indicadores calculados -->
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
                <div class="label">% Finalizados en término</div>
                <div class="value">{{ pctOrDash(calc?.porcentaje_finalizados_en_termino) }}</div>
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

        <!-- Distribución: un indicador por fila, con cantidad e importe -->
        <h3 style="margin:24px 0 10px;">Distribución</h3>
        <div style="display:grid;grid-template-columns:1fr;gap:16px;">
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">
                    Por Gerencia de Área
                    <span style="font-weight:400;font-size:12px;color:var(--color-muted);">
                        · saldo y cantidad de contratos
                    </span>
                </h4>
                <BarChart :rows="rowsPorArea" money />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">
                    Por Subsector
                    <span style="font-weight:400;font-size:12px;color:var(--color-muted);">
                        · saldo y cantidad de contratos
                    </span>
                </h4>
                <BarChart :rows="rowsPorSector" money />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">
                    Por UVT
                    <span style="font-weight:400;font-size:12px;color:var(--color-muted);">
                        · saldo y cantidad de contratos
                    </span>
                </h4>
                <BarChart :rows="rowsPorUvt" money />
            </div>
        </div>

        <!-- Movimientos de ejecución por acción -->
        <h3 style="margin:24px 0 10px;">Ejecución por acción</h3>
        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th>Tipo</th>
                        <th style="text-align:right;">Movimientos</th>
                        <th style="text-align:right;">Total (ARS)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(m, i) in acciones?.movimientos || []" :key="i">
                        <td>{{ ACCION_LABELS[m.accion] || m.accion }}</td>
                        <td>
                            <span :class="['badge', m.tipo === 'ingreso' ? 'badge-success' : 'badge-warning']">
                                {{ m.tipo }}
                            </span>
                        </td>
                        <td style="text-align:right;">{{ fmtInt(m.cantidad) }}</td>
                        <td style="text-align:right;">{{ fmtMoney(m.total) }}</td>
                    </tr>
                    <tr v-if="!acciones?.movimientos?.length">
                        <td colspan="4" class="empty-state">Sin movimientos cargados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Vencimientos -->
        <h3 style="margin:24px 0 10px;">Próximos vencimientos</h3>
        <div class="kpi-grid">
            <div class="kpi-card danger">
                <div class="label">Vencidos</div>
                <div class="value">{{ fmtInt(venc?.vencidos) }}</div>
            </div>
            <div class="kpi-card warning">
                <div class="label">≤ 30 días</div>
                <div class="value">{{ fmtInt(venc?.dias_30) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">31–60 días</div>
                <div class="value">{{ fmtInt(venc?.dias_60) }}</div>
            </div>
            <div class="kpi-card">
                <div class="label">61–90 días</div>
                <div class="value">{{ fmtInt(venc?.dias_90) }}</div>
            </div>
        </div>

        <!-- Rankings -->
        <h3 style="margin:24px 0 10px;">Rankings</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">Gerencias de Área con más contratos</h4>
                <table class="atlas-table">
                    <thead><tr><th>Gerencia de Área</th><th style="text-align:right;">Cantidad</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in ranks?.gerencias_area_por_cantidad || []" :key="i">
                            <td>{{ r.gerencia_area || '—' }}</td>
                            <td style="text-align:right;">{{ fmtInt(r.cantidad) }}</td>
                        </tr>
                        <tr v-if="!ranks?.gerencias_area_por_cantidad?.length">
                            <td colspan="2" class="empty-state">Sin datos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">UVT por saldo ({{ ranks?.moneda_base }})</h4>
                <table class="atlas-table">
                    <thead>
                        <tr>
                            <th>UVT</th>
                            <th style="text-align:right;">Saldo inicial</th>
                            <th style="text-align:right;">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in ranks?.uvt_por_monto || []" :key="i">
                            <td>{{ r.siglas }}<template v-if="r.nombre"> — {{ r.nombre }}</template></td>
                            <td style="text-align:right;">{{ fmtMoney(r.saldo_inicial) }}</td>
                            <td style="text-align:right;">{{ fmtMoney(r.saldo) }}</td>
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
import { authService } from '@/services/auth';
import { exportFullService } from '@/services/exportFull';
import { listAll } from '@/services/catalogos';
import { AGRUPACIONES_SALDO as AGRUPACIONES, useAuthStore } from '@/stores/auth';
import { fmtInt, fmtMoney } from '@/composables/useFormat';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import BarChart from '@/components/BarChart.vue';
import IconLib from '@/components/IconLib.vue';

const ACCION_LABELS = {
    factura:       'Factura',
    transferencia: 'Transferencia entre contratos',
    incentivo:     'Incentivos',
    mch:           'MCH (Mayor Carga Horaria)',
};

const toast = useToast();
const auth = useAuthStore();

const filters = reactive({
    desde: '',
    hasta: '',
    moneda_base: 'Peso',
    gerencia_area_id: '',
    sector_id: '',
});

// Agrupación con la que se muestran los saldos; arranca en la preferencia del usuario.
const agrupacion = ref(auth.saldosAgrupacion);

const ind = ref(null);
const calc = ref(null);
const saldos = ref(null);
const dUvt = ref(null);
const dGer = ref(null);
const acciones = ref(null);
const venc = ref(null);
const ranks = ref(null);
const sectores = ref([]);
const loading = ref(false);
const exporting = ref(false);
const guardandoPref = ref(false);

const alcance = computed(() => {
    if (auth.isAdminSistema) return 'Todas las Gerencias de Área';
    return auth.gerenciaArea ? `Gerencia de Área ${auth.gerenciaArea}` : '';
});

/** Los sectores sin dependencia son las Gerencias de Área. */
const areas = computed(() => sectores.value.filter(s => s.dependencia_id === null));

const tituloColumnaSaldos = computed(() => ({
    gerencia_area: 'Gerencia de Área',
    subsector:     'Gerencia de Área / Subsector',
    contrato:      'Gerencia de Área / Subsector / Contrato',
}[agrupacion.value] || 'Gerencia de Área'));

const subsectoresFiltrados = computed(() => {
    const hijos = sectores.value.filter(s => s.dependencia_id !== null);
    if (!filters.gerencia_area_id) return hijos;
    return hijos.filter(s => String(s.dependencia_id) === String(filters.gerencia_area_id));
});

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
    for (const [k, v] of Object.entries(filters)) {
        if (v !== '' && v !== null && v !== undefined) p[k] = v;
    }
    return p;
}

async function setAgrupacion(valor) {
    agrupacion.value = valor;
    await loadSaldos();
}

async function guardarPreferencia() {
    guardandoPref.value = true;
    try {
        const r = await authService.savePreferencias({ saldos_agrupacion: agrupacion.value });
        if (r?.user) auth.setUser(r.user);
        toast.success('Preferencia de saldos guardada.');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo guardar la preferencia.'));
    } finally {
        guardandoPref.value = false;
    }
}

async function loadSaldos() {
    try {
        saldos.value = await panelService.saldos({ ...paramsClean(), agrupacion: agrupacion.value });
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los saldos.'));
    }
}

async function loadAll() {
    loading.value = true;
    try {
        const p = paramsClean();
        const [a, b, c, d, e, f, g] = await Promise.all([
            panelService.indicadores(p),
            panelService.calculados(p),
            panelService.saldos({ ...p, agrupacion: agrupacion.value }),
            panelService.porUvt(p),
            panelService.porGerencia(p),
            panelService.porAccion(p),
            panelService.vencimientos(p),
        ]);
        ind.value = a; calc.value = b; saldos.value = c; dUvt.value = d;
        dGer.value = e; acciones.value = f; venc.value = g;
        ranks.value = await panelService.rankings(p);
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los indicadores.'));
    } finally {
        loading.value = false;
    }
}

function clearFilters() {
    filters.desde = ''; filters.hasta = ''; filters.moneda_base = 'Peso';
    filters.gerencia_area_id = ''; filters.sector_id = '';
    loadAll();
}

function pctOrDash(v) {
    if (v === null || v === undefined) return '—';
    return `${v}%`;
}

/**
 * La barra se dimensiona por el saldo, que es lo que interesa comparar entre
 * gerencias; la cantidad de contratos acompaña al costado.
 */
function conImporte(filas, etiqueta) {
    return (filas || []).map(r => ({
        label: etiqueta(r) || '—',
        value: Number(r.saldo) || 0,
        extra: `${fmtInt(r.cantidad)} contr.`,
    }));
}

const rowsPorUvt    = computed(() => conImporte(dUvt.value?.contratos, r => r.siglas));
const rowsPorSector = computed(() => conImporte((dGer.value?.sectores || []).slice(0, 20), r => r.nombre));
const rowsPorArea   = computed(() => conImporte(dGer.value?.gerencias_area, r => r.nombre));

onMounted(async () => {
    try {
        sectores.value = await listAll('sectores');
    } catch { /* no-op */ }
    loadAll();
});
</script>

<style scoped>
.saldo-row.nivel-0 { background: var(--color-surface-alt, rgba(0, 0, 0, 0.03)); font-weight: 600; }
.saldo-row .raiz { font-weight: 600; }
.saldo-row .rama { color: var(--color-muted, #888); margin-right: 4px; }
</style>
