<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Panel de Control</h1>
                <p class="page-subtitle">
                    Indicadores, saldos y distribución de expedientes
                    <template v-if="alcance"> · {{ alcance }}</template>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn btn-secondary" :disabled="loading || generandoPdf" @click="descargarPdf">
                    <span v-if="generandoPdf" class="loader dark" />
                    <IconLib v-else name="download" />
                    {{ generandoPdf ? 'Generando…' : 'Descargar PDF' }}
                </button>
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
                    <SelectBuscador v-model="filters.moneda_base"
                                    :opciones="[{ value: 'Peso', etiqueta: 'Peso' },
                                                { value: 'Dólar', etiqueta: 'Dólar' },
                                                { value: 'Euro', etiqueta: 'Euro' }]" />
                </div>
                <div class="field">
                    <label>Gerencia de Área</label>
                    <SelectBuscador v-model="filters.gerencia_area_id"
                                    :opciones="areas.map(a => ({ value: a.sector_id, etiqueta: a.nombre }))"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas" />
                </div>
                <div class="field">
                    <label>Gerencia</label>
                    <SelectBuscador v-model="filters.sector_id"
                                    :opciones="gerenciasFiltradas.map(g => ({ value: g.sector_id, etiqueta: g.nombre }))"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas" />
                </div>
                <div class="field">
                    <label>Contrato</label>
                    <SelectBuscador v-model="filters.nodo_id"
                                    :opciones="contratosFiltrados.map(c => ({ value: c.sector_id, etiqueta: c.nombre }))"
                                    opcion-vacia="Todos" valor-vacio="" placeholder="Todos" />
                </div>
                <div class="field">
                    <label>Cuenta operativa</label>
                    <SelectBuscador v-model="filters.cuenta_operativa_id"
                                    :opciones="cuentasFiltradas.map(c => ({ value: c.id, etiqueta: c.nombre }))"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas" />
                    <div class="hint">Los expedientes imputados a esa cuenta.</div>
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
                        <ThOrden campo="etiqueta" :orden="ordenSaldos" @ordenar="ordenarSaldos">
                            {{ tituloColumnaSaldos }}
                        </ThOrden>
                        <ThOrden campo="cuentas" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Cuentas</ThOrden>
                        <ThOrden campo="contratos" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Contratos</ThOrden>
                        <ThOrden campo="saldo_inicial" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Saldo inicial</ThOrden>
                        <ThOrden campo="ejecutado_ingresos" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Ingresos</ThOrden>
                        <ThOrden campo="ejecutado_gastos" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Gastos</ThOrden>
                        <ThOrden campo="saldo" :orden="ordenSaldos" derecha @ordenar="ordenarSaldos">Saldo</ThOrden>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in filasSaldos" :key="f.clave"
                        :class="['saldo-row', `nivel-${f.nivel}`, `alcance-${f.alcance}`]">
                        <td>
                            <div :style="{ paddingLeft: `${f.nivel * 20}px` }">
                                <template v-if="f.alcance === 'acumulado'">
                                    <span v-if="f.nivel > 0" class="rama">└</span>
                                    <span :class="{ raiz: f.nivel === 0 }">{{ f.etiqueta }}</span>
                                </template>
                                <span v-else class="alcance-propios-label">Sólo lo propio</span>
                            </div>
                            <div v-if="f.alcance === 'acumulado'"
                                 :style="{ paddingLeft: `${f.nivel * 20 + (f.nivel > 0 ? 14 : 0)}px` }"
                                 style="font-size:11px;color:var(--color-muted);">
                                {{ NIVELES_ARBOL[f.tipo] || f.tipo }} · acumulado de la rama
                            </div>
                        </td>
                        <td style="text-align:right;">{{ fmtInt(f.cuentas) }}</td>
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
                        <td colspan="7" class="empty-state">Sin datos para el filtro aplicado.</td>
                    </tr>
                    <tr v-else style="font-weight:600;border-top:2px solid var(--color-border);">
                        <td>Total ({{ saldos?.moneda_base }})</td>
                        <td style="text-align:right;">{{ fmtInt(saldos?.totales?.cuentas) }}</td>
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
                <div class="label">Cuentas</div>
                <div class="value">{{ fmtInt(ind?.totales?.cuentas) }}</div>
            </div>
            <div class="kpi-card info">
                <div class="label">Contratos</div>
                <div class="value">{{ fmtInt(ind?.totales?.contratos) }}</div>
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

        <!-- Distribución: un indicador por fila, con cantidad e importe -->
        <h3 style="margin:24px 0 10px;">Distribución</h3>
        <div style="display:grid;grid-template-columns:1fr;gap:16px;">
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">
                    Por Gerencia de Área
                    <span style="font-weight:400;font-size:12px;color:var(--color-muted);">
                        · saldo y cantidad de cuentas
                    </span>
                </h4>
                <BarChart :rows="rowsPorArea" money />
            </div>
            <div class="card">
                <h4 style="margin:0 0 10px;color:var(--color-primary);">
                    Por Gerencia
                    <span style="font-weight:400;font-size:12px;color:var(--color-muted);">
                        · saldo y cantidad de cuentas
                    </span>
                </h4>
                <BarChart :rows="rowsPorSector" money />
            </div>
        </div>

        <!-- Movimientos de ejecución por acción -->
        <h3 style="margin:24px 0 10px;">Ejecución por acción</h3>
        <div class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <ThOrden campo="accion" :orden="ordenAcciones" @ordenar="ordenarAcciones">Acción</ThOrden>
                        <ThOrden campo="tipo" :orden="ordenAcciones" @ordenar="ordenarAcciones">Tipo</ThOrden>
                        <ThOrden campo="cantidad" :orden="ordenAcciones" derecha @ordenar="ordenarAcciones">Movimientos</ThOrden>
                        <ThOrden campo="total" :orden="ordenAcciones" derecha @ordenar="ordenarAcciones">Total (ARS)</ThOrden>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(m, i) in filasAcciones" :key="i">
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

    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { panelService } from '@/services/panel';
import { authService } from '@/services/auth';
import { exportFullService } from '@/services/exportFull';
import { expedientesService } from '@/services/expedientes';
import { AGRUPACIONES_SALDO as AGRUPACIONES, useAuthStore } from '@/stores/auth';
import { useOrdenTabla } from '@/composables/useOrden';
import ThOrden from '@/components/ThOrden.vue';
import { fmtInt, fmtMoney } from '@/composables/useFormat';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import BarChart from '@/components/BarChart.vue';
import IconLib from '@/components/IconLib.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';

const ACCION_LABELS = {
    factura:       'Factura',
    transferencia: 'Transferencia entre expedientes',
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
    nodo_id: '',
    cuenta_operativa_id: '',
});

// Agrupación con la que se muestran los saldos; arranca en la preferencia del usuario.
/** Etiquetas de los niveles del árbol, para la columna de saldos. */
const NIVELES_ARBOL = {
    gerencia_area: 'Gerencia de Área',
    gerencia:      'Gerencia',
    contrato:      'Contrato',
};

const agrupacion = ref(auth.saldosAgrupacion);

const ind = ref(null);
const saldos = ref(null);
const dGer = ref(null);
const acciones = ref(null);
const arbol = ref(null);
const loading = ref(false);
const exporting = ref(false);
const guardandoPref = ref(false);
const generandoPdf = ref(false);

/**
 * La tabla de saldos es un árbol: cada nodo trae su fila de acumulado, la de lo
 * propio, y debajo sus hijos. Ordenar no puede mezclar ramas, así que se
 * ordenan los hermanos entre sí —por lo que muestra la fila de acumulado— y el
 * árbol se vuelve a aplanar en ese orden.
 */
const ordenSaldos = reactive({ by: '', dir: 'asc' });

function ordenarSaldos(campo) {
    if (ordenSaldos.by === campo) {
        ordenSaldos.dir = ordenSaldos.dir === 'asc' ? 'desc' : 'asc';
    } else {
        ordenSaldos.by = campo;
        ordenSaldos.dir = 'asc';
    }
}

const filasSaldos = computed(() => {
    const filas = saldos.value?.filas || [];
    if (!ordenSaldos.by) return filas;

    // Las dos filas de un nodo comparten clave: 's-12-acumulado' y 's-12-propios'.
    const claveNodo = (f) => String(f.clave).replace(/-(acumulado|propios)$/, '');
    const nodos = new Map();
    for (const f of filas) {
        const clave = claveNodo(f);
        if (!nodos.has(clave)) nodos.set(clave, { clave, padre: f.padre_clave ?? null, filas: [], hijos: [] });
        nodos.get(clave).filas.push(f);
    }

    const raices = [];
    for (const nodo of nodos.values()) {
        const padre = nodo.padre !== null ? nodos.get(nodo.padre) : null;
        (padre ? padre.hijos : raices).push(nodo);
    }

    const signo = ordenSaldos.dir === 'asc' ? 1 : -1;
    const valor = (nodo) => {
        const fila = nodo.filas.find(f => f.alcance === 'acumulado') || nodo.filas[0];
        const v = fila?.[ordenSaldos.by];
        return ordenSaldos.by === 'etiqueta' ? String(v ?? '') : Number(v ?? 0);
    };
    const comparar = (a, b) => {
        const va = valor(a);
        const vb = valor(b);
        const c = typeof va === 'string'
            ? va.localeCompare(vb, 'es', { numeric: true, sensitivity: 'base' })
            : va - vb;
        return signo * c;
    };

    const aplanar = (lista, salida) => {
        for (const nodo of [...lista].sort(comparar)) {
            salida.push(...nodo.filas);
            aplanar(nodo.hijos, salida);
        }
        return salida;
    };

    return aplanar(raices, []);
});

// Tablas del panel: las filas vienen completas, así que ordenan en el navegador.
// La de saldos queda afuera: es un árbol, y reordenarla lo desarmaría.
const {
    orden: ordenAcciones, ordenarPor: ordenarAcciones, filasOrdenadas: filasAcciones,
} = useOrdenTabla(computed(() => acciones.value?.movimientos || []));

const alcance = computed(() => {
    return auth.veTodo ? 'Todas las Gerencias de Área' : auth.alcanceLabel;
});

/**
 * El árbol aplanado, con el nivel y el ancestro de cada nodo, para armar los
 * selectores encadenados de la barra de filtros.
 */
const nodos = computed(() => {
    const filas = [];
    const recorrer = (nodo, nivel, ancestros) => {
        if (nodo.sector_id !== null) {
            filas.push({
                sector_id: nodo.sector_id,
                nombre:    nodo.nombre,
                nivel,
                ancestros,
                cuentas:   nodo.cuentas,
            });
        }
        const deLosHijos = nodo.sector_id === null ? ancestros : [...ancestros, String(nodo.sector_id)];
        for (const h of nodo.hijos) recorrer(h, nivel + 1, deLosHijos);
    };
    if (arbol.value) recorrer(arbol.value, 0, []);
    return filas;
});

const areas = computed(() => nodos.value.filter(n => n.nivel === 1));

const tituloColumnaSaldos = computed(() => ({
    gerencia_area: 'Gerencia de Área',
    gerencia:      'Gerencia de Área / Gerencia',
    contrato:      'Gerencia de Área / Gerencia / Contrato',
}[agrupacion.value] || 'Gerencia de Área'));

/** Selectores de la estructura, de arriba hacia abajo, con su nivel en el árbol. */
const FILTROS_ESTRUCTURA = [
    ['gerencia_area_id', 1],
    ['sector_id',        2],
    ['nodo_id',          3],
];

/** Nodos de un nivel que caen dentro de lo elegido en los selectores de arriba. */
function nodosDelNivel(nivel) {
    const elegidos = FILTROS_ESTRUCTURA
        .filter(([campo, n]) => n < nivel && filters[campo])
        .map(([campo]) => String(filters[campo]));
    return nodos.value.filter(n => n.nivel === nivel && elegidos.every(id => n.ancestros.includes(id)));
}

/** Gerencias: segundo nivel, acotado a la Gerencia de Área elegida. */
const gerenciasFiltradas = computed(() => nodosDelNivel(2));

/** Contratos: tercer nivel, acotado a la Gerencia elegida. */
const contratosFiltrados = computed(() => nodosDelNivel(3));

/** Cuentas de la rama del nodo más profundo que se haya elegido. */
const cuentasFiltradas = computed(() => {
    const elegido = [...FILTROS_ESTRUCTURA].reverse().find(([campo]) => filters[campo]);
    if (!elegido) return nodos.value.flatMap(n => n.cuentas);
    const id = String(filters[elegido[0]]);
    return nodos.value
        .filter(n => String(n.sector_id) === id || n.ancestros.includes(id))
        .flatMap(n => n.cuentas);
});

// Al cambiar un nivel se descartan los de abajo, que ya no le pertenecen.
FILTROS_ESTRUCTURA.forEach(([campo], i) => {
    watch(() => filters[campo], () => {
        for (const [abajo] of FILTROS_ESTRUCTURA.slice(i + 1)) filters[abajo] = '';
        filters.cuenta_operativa_id = '';
    });
});

/**
 * Descripción de los filtros aplicados, para que el PDF diga de qué recorte
 * salieron los números.
 */
function descripcionFiltros() {
    const nombre = (id) => nodos.value.find(n => String(n.sector_id) === String(id))?.nombre;
    const partes = [];
    if (filters.desde) partes.push(`desde ${filters.desde}`);
    if (filters.hasta) partes.push(`hasta ${filters.hasta}`);
    if (filters.moneda_base) partes.push(`moneda base ${filters.moneda_base}`);
    if (filters.gerencia_area_id) partes.push(`Gerencia de Área: ${nombre(filters.gerencia_area_id)}`);
    if (filters.sector_id) partes.push(`Gerencia: ${nombre(filters.sector_id)}`);
    if (filters.nodo_id) partes.push(`Contrato: ${nombre(filters.nodo_id)}`);
    if (filters.cuenta_operativa_id) {
        const cta = cuentasFiltradas.value.find(c => String(c.id) === String(filters.cuenta_operativa_id));
        partes.push(`Cuenta: ${cta?.nombre || filters.cuenta_operativa_id}`);
    }
    return partes;
}

async function descargarPdf() {
    generandoPdf.value = true;
    try {
        // La carga del módulo se difiere: el armado del PDF pesa y sólo hace
        // falta cuando alguien lo pide.
        const { generarPanelPdf } = await import('@/services/panelPdf');
        generarPanelPdf({
            ind: ind.value,
            saldos: saldos.value,
            dGer: dGer.value,
            acciones: acciones.value,
            filtrosAplicados: descripcionFiltros(),
            alcance: alcance.value,
            usuario: auth.user?.display_name || auth.user?.username || '',
            tituloSaldos: tituloColumnaSaldos.value,
            accionLabels: ACCION_LABELS,
        });
        toast.success('PDF generado.');
    } catch (err) {
        toast.error(extractError(err, 'No se pudo generar el PDF.'));
    } finally {
        generandoPdf.value = false;
    }
}

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
        const [a, c, e, f] = await Promise.all([
            panelService.indicadores(p),
            panelService.saldos({ ...p, agrupacion: agrupacion.value }),
            panelService.porGerencia(p),
            panelService.porAccion(p),
        ]);
        ind.value = a; saldos.value = c; dGer.value = e; acciones.value = f;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los indicadores.'));
    } finally {
        loading.value = false;
    }
}

function clearFilters() {
    filters.desde = ''; filters.hasta = ''; filters.moneda_base = 'Peso';
    filters.gerencia_area_id = ''; filters.sector_id = '';
    filters.nodo_id = ''; filters.cuenta_operativa_id = '';
    loadAll();
}

/**
 * La barra se dimensiona por el saldo, que es lo que interesa comparar entre
 * gerencias; la cantidad de cuentas acompaña al costado.
 */
function conImporte(filas, etiqueta) {
    return (filas || []).map(r => ({
        label: etiqueta(r) || '—',
        value: Number(r.saldo) || 0,
        extra: `${fmtInt(r.cantidad)} ctas.`,
    }));
}

// Se muestran todas las gerencias: recortar a las primeras hacía que la suma
// del gráfico no coincidiera con el total de expedientes del panel.
const rowsPorSector = computed(() => conImporte(dGer.value?.sectores, r => r.nombre));
const rowsPorArea   = computed(() => conImporte(dGer.value?.gerencias_area, r => r.nombre));

onMounted(async () => {
    try {
        arbol.value = await expedientesService.arbolEstructura();
    } catch { /* no-op */ }
    loadAll();
});
</script>

<style scoped>
.saldo-row.nivel-0 { background: var(--color-surface-alt, rgba(0, 0, 0, 0.03)); font-weight: 600; }
.saldo-row .raiz { font-weight: 600; }
.saldo-row .rama { color: var(--color-muted, #888); margin-right: 4px; }

/* El acumulado encabeza el nodo; lo propio va debajo y cierra el bloque. */
.saldo-row.alcance-acumulado { font-weight: 600; }
.saldo-row.alcance-propios td { border-bottom: 1px solid var(--color-border, #e0e0e0); }
.alcance-propios-label {
    font-size: 12px;
    font-style: italic;
    color: var(--color-muted, #888);
}
</style>
