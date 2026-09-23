<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Expedientes</h1>
                <p class="page-subtitle">
                    Cada expediente va contra una cuenta
                    <template v-if="!auth.veTodo"> · {{ auth.alcanceLabel }}</template>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn btn-secondary" @click="exportar">
                    <IconLib name="download" /> Exportar Excel
                </button>
                <router-link v-if="auth.canEdit" :to="{ name: 'expedientes-nuevo' }" class="btn btn-primary">
                    <IconLib name="plus" /> Nuevo
                </router-link>
            </div>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="filters.search" type="text" class="input"
                           placeholder="Número de expediente…" @input="onFilter" />
                </div>
                <div v-if="auth.veTodo" class="field">
                    <label>Gerencia de Área</label>
                    <SelectBuscador v-model="filters.gerencia_area_id"
                                    :opciones="areas.map(a => ({ value: a.sector_id, etiqueta: a.nombre }))"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas"
                                    @update:model-value="onFilter" />
                </div>
                <div class="field">
                    <label>Gerencia</label>
                    <SelectBuscador v-model="filters.sector_id"
                                    :opciones="nodosDelNivel(2).map(g => ({ value: g.sector_id, etiqueta: g.nombre }))"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas"
                                    @update:model-value="onFilter" />
                </div>
                <div class="field">
                    <label>Contrato</label>
                    <SelectBuscador v-model="filters.nodo_id"
                                    :opciones="nodosDelNivel(3).map(c => ({ value: c.sector_id, etiqueta: c.nombre }))"
                                    opcion-vacia="Todos" valor-vacio="" placeholder="Todos"
                                    @update:model-value="onFilter" />
                </div>
                <div v-if="auth.veTodo" class="field">
                    <label>Mostrar dados de baja</label>
                    <SelectBuscador v-model="filters.mostrar_baja"
                                    :opciones="[{ value: '', etiqueta: 'No' }, { value: '1', etiqueta: 'Sí' }]"
                                    @update:model-value="onFilter" />
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">No hay expedientes.</div>

        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <template v-for="col in columnas" :key="col.campo || col.label">
                            <ThOrden v-if="col.campo" :campo="col.campo" :orden="orden"
                                     @ordenar="ordenarPor">{{ col.label }}</ThOrden>
                            <th v-else>{{ col.label }}</th>
                        </template>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :class="{ deleted: r.deleted_at }">
                        <td>{{ r.id }}</td>
                        <td>
                            <router-link :to="{ name: 'expedientes-detalle', params: { id: r.id } }">
                                {{ r.nro_expediente }}
                            </router-link>
                        </td>
                        <td>
                            <div>{{ r.estructura?.gerencia?.nombre || r.sector?.nombre || '—' }}</div>
                            <div v-if="bajoLaGerencia(r)" style="font-size:12px;">
                                {{ bajoLaGerencia(r) }}
                            </div>
                            <div v-if="r.gerencia_area" style="font-size:11px;color:var(--color-muted);">
                                {{ r.gerencia_area.nombre }}
                            </div>
                        </td>
                        <td>
                            <router-link v-if="r.cuenta_operativa"
                                         :to="{ name: 'cuenta-detalle', params: { id: r.cuenta_operativa.id } }">
                                {{ r.cuenta_operativa.nombre }}
                            </router-link>
                            <span v-else>—</span>
                        </td>
                        <td>
                            <div class="ejecutado">
                                <div><span>Ing.:</span> {{ fmtMoney(r.monto_ejecutado_ingresos) }}</div>
                                <div><span>Gtos.:</span> {{ fmtMoney(r.monto_ejecutado_gastos) }}</div>
                                <div class="saldo" :class="{ negativo: Number(r.saldo) < 0 }">
                                    <span>Resultado:</span> {{ fmtMoney(r.saldo) }}
                                </div>
                            </div>
                        </td>
                        <td class="actions">
                            <router-link v-if="auth.canEdit && !r.deleted_at"
                                         :to="{ name: 'expedientes-editar', params: { id: r.id } }">
                                <button><IconLib name="edit" :size="14" /></button>
                            </router-link>
                            <button v-if="auth.canEdit && !r.deleted_at" class="danger" @click="darBaja(r)">
                                <IconLib name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="pie-grilla">
                <label>
                    Ver
                    <SelectBuscador v-model="perPage"
                                    :opciones="[20, 50, 100, 200].map(n => ({ value: n, etiqueta: String(n) }))"
                                    @update:model-value="cambiarTamanio" />
                    por página
                </label>
                <BasePager :page="page" :per-page="perPage" :total="total" @change="goto" />
            </div>
        </div>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { expedientesService } from '@/services/expedientes';
import { listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtMoney, debounce } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';
import BasePager from '@/components/BasePager.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ThOrden from '@/components/ThOrden.vue';

const auth = useAuthStore();
const toast = useToast();

const rows = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(20);
const loading = ref(false);
const confirmRef = ref(null);

const filters = reactive({
    search: '',
    gerencia_area_id: '',
    sector_id: '',
    nodo_id: '',
    mostrar_baja: '',
});

const sectores = ref([]);

/** Selectores de la estructura, de arriba hacia abajo, con su nivel en el árbol. */
const FILTROS_ESTRUCTURA = [
    ['gerencia_area_id', 1],
    ['sector_id',        2],
    ['nodo_id',          3],
];

/** Cada nodo con su nivel y sus ancestros, deducidos de la dependencia. */
const nodos = computed(() => {
    const porId = new Map(sectores.value.map(s => [String(s.sector_id), s]));
    return sectores.value.map(s => {
        const ancestros = [];
        const visitados = new Set([String(s.sector_id)]);
        let padre = s.dependencia_id;
        while (padre !== null && padre !== undefined && porId.has(String(padre))
               && !visitados.has(String(padre))) {
            visitados.add(String(padre));
            ancestros.unshift(String(padre));
            padre = porId.get(String(padre)).dependencia_id;
        }
        return { ...s, nivel: ancestros.length + 1, ancestros };
    });
});

/** Las Gerencias de Área: los nodos que no dependen de ningún otro. */
const areas = computed(() => nodos.value.filter(n => n.nivel === 1));

/** Nodos de un nivel que caen dentro de lo elegido en los selectores de arriba. */
function nodosDelNivel(nivel) {
    const elegidos = FILTROS_ESTRUCTURA
        .filter(([campo, n]) => n < nivel && filters[campo])
        .map(([campo]) => String(filters[campo]));
    return nodos.value.filter(n => n.nivel === nivel && elegidos.every(id => n.ancestros.includes(id)));
}

// Al cambiar un nivel se descartan los de abajo, que ya no le pertenecen.
FILTROS_ESTRUCTURA.forEach(([campo], i) => {
    watch(() => filters[campo], () => {
        for (const [abajo] of FILTROS_ESTRUCTURA.slice(i + 1)) filters[abajo] = '';
    });
});

/** Contrato del expediente, cuando se imputa por debajo de la Gerencia. */
function bajoLaGerencia(r) {
    return r.estructura?.contrato?.nombre || '';
}

/**
 * Columnas de la grilla. `campo` es lo que se manda al backend para ordenar;
 * las que no lo tienen no son ordenables.
 */
const columnas = [
    { label: 'ID',          campo: 'id' },
    { label: 'Expediente',  campo: 'nro_expediente' },
    { label: 'Gerencia',    campo: 'sector' },
    { label: 'Cuenta',      campo: 'cuenta' },
    { label: 'Movimientos relacionados', campo: 'saldo' },
];

const orden = reactive({ by: 'id', dir: 'desc' });

/** Un clic ordena ascendente; el siguiente sobre la misma columna invierte. */
function ordenarPor(campo) {
    if (orden.by === campo) {
        orden.dir = orden.dir === 'asc' ? 'desc' : 'asc';
    } else {
        orden.by = campo;
        orden.dir = 'asc';
    }
    page.value = 1;
    load();
}

function cambiarTamanio() {
    page.value = 1;
    load();
}

const onFilter = debounce(() => { page.value = 1; load(); }, 300);

async function load() {
    loading.value = true;
    try {
        const params = {
            page: page.value,
            per_page: perPage.value,
            order_by: orden.by,
            order_dir: orden.dir,
        };
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) params[k] = v;
        }
        const res = await expedientesService.list(params);
        rows.value = res.data || [];
        total.value = res.total || 0;
        perPage.value = Number(res.per_page) || perPage.value;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los expedientes.'));
    } finally {
        loading.value = false;
    }
}

function goto(p) { page.value = p; load(); }

async function darBaja(r) {
    const ok = await confirmRef.value.show({
        title: 'Dar de baja',
        message: `¿Confirma dar de baja el expediente #${r.id}? Queda registrado en el historial.`,
        confirmText: 'Dar de baja',
        danger: true,
    });
    if (!ok) return;
    try {
        await expedientesService.remove(r.id);
        toast.success('Expediente dado de baja.');
        load();
    } catch (err) {
        toast.error(extractError(err, 'No se pudo dar de baja.'));
    }
}

async function exportar() {
    try {
        const params = { order_by: orden.by, order_dir: orden.dir };
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) params[k] = v;
        }
        await expedientesService.exportExcel(params);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo exportar.'));
    }
}

onMounted(async () => {
    try {
        sectores.value = await listAll('sectores');
    } catch { /* no-op */ }
    load();
});
</script>

<style scoped>
.pie-grilla {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.pie-grilla > label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-muted, #888);
    white-space: nowrap;
}
.pie-grilla .select { width: auto; padding: 4px 8px; height: auto; font-size: 13px; }

.ejecutado { font-size: 12px; line-height: 1.35; white-space: nowrap; }
.ejecutado span { color: var(--color-muted, #888); }
.ejecutado .saldo {
    font-weight: 600;
    border-top: 1px solid var(--color-border, #e3e6ea);
    margin-top: 3px;
    padding-top: 3px;
}
.ejecutado .saldo.negativo { color: var(--color-danger, #c0392b); }
</style>
