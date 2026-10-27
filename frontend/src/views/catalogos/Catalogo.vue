<template>
    <div v-if="!def" class="empty-state">Catálogo no encontrado.</div>

    <div v-else>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">{{ def.title }}</h1>
                <p class="page-subtitle">{{ def.subtitle || 'Listado y gestión del catálogo' }}</p>
            </div>
            <div>
                <button v-if="auth.canAdminEstructura && !def.sinAlta" class="btn btn-primary" @click="openNew">
                    <IconLib name="plus" /> Nuevo
                </button>
            </div>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <input v-model="state.search" type="text" class="input"
                       :placeholder="def.buscar || 'Buscar...'" @input="onSearch" />
            </div>
            <button v-if="def.filtros" type="button" class="btn btn-secondary" @click="verFiltros = !verFiltros">
                {{ verFiltros ? 'Ocultar filtros' : 'Filtros' }}<template v-if="filtrosActivos"> ({{ filtrosActivos }})</template>
            </button>
            <button v-if="def.filtros && filtrosActivos" type="button" class="btn btn-secondary" @click="limpiarFiltros">
                Limpiar filtros
            </button>
            <label v-if="def.archivo" style="display:flex;align-items:center;gap:6px;font-size:13px;">
                <input type="checkbox" v-model="state.archivados" style="width:auto;margin:0;"
                       @change="state.page = 1; load()" />
                {{ def.archivo.verArchivados }}
            </label>
        </div>

        <!-- Filtros del catálogo: se combinan entre sí y con la búsqueda. -->
        <div v-if="def.filtros && verFiltros" class="filters-panel">
            <div class="filters-grid">
                <div v-for="f in def.filtros" :key="f.param" class="field">
                    <label>{{ f.label }}</label>
                    <input v-if="f.type === 'date' || f.type === 'number'" v-model="filtros[f.param]"
                           :type="f.type" class="input" :step="f.step" @change="aplicarFiltro(f)" />
                    <SelectBuscador v-else v-model="filtros[f.param]"
                                    :opciones="opcionesFiltro(f)"
                                    :opcion-vacia="f.todos || 'Todos'" valor-vacio=""
                                    :placeholder="f.todos || 'Todos'"
                                    @update:model-value="aplicarFiltro(f)" />
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">Sin registros.</div>
        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <template v-for="c in def.columns" :key="c.key">
                            <ThOrden v-if="c.orden !== false" :campo="c.key" :orden="orden"
                                     @ordenar="ordenarPor">{{ c.label }}</ThOrden>
                            <th v-else>{{ c.label }}</th>
                        </template>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r[def.keyField]">
                        <td v-for="c in def.columns" :key="c.key">
                            <router-link v-if="c.link" :to="c.link(r)">
                                {{ c.render ? c.render(r) : (r[c.key] ?? '—') }}
                            </router-link>
                            <a v-else-if="c === columnaPrincipal" href="#" @click.prevent="abrir(r)"
                               :title="def.abrirEnConsulta || !puedeEditar(r) ? 'Ver el registro' : 'Abrir para editar'">
                                {{ c.render ? c.render(r) : (r[c.key] ?? '—') }}
                            </a>
                            <template v-else>{{ c.render ? c.render(r) : (r[c.key] ?? '—') }}</template>
                        </td>
                        <td class="actions">
                            <button v-if="puedeEditar(r)" @click="openEdit(r)" title="Editar">
                                <IconLib name="edit" :size="14" />
                            </button>
                            <button v-if="def.archivo && auth.canAdminEstructura" @click="archivar(r)"
                                    :title="def.archivo.archivado(r) ? 'Desarchivar' : 'Archivar'">
                                <IconLib name="archive" :size="14" />
                            </button>
                            <button v-if="auth.canAdminEstructura && !def.sinBaja" class="danger" @click="remove(r)" title="Eliminar">
                                <IconLib name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <BasePager :page="state.page" :per-page="state.per_page" :total="total" @change="goto" />
        </div>

        <BaseModal v-model="formOpen" :title="formTitle" :size="def.modalGrande ? 'large' : ''">
            <form @submit.prevent="save">
                <fieldset :disabled="soloLectura" class="sin-marco">
                <div class="form-grid">
                    <div v-for="f in def.formFields" :key="f.name" class="field"
                         :class="{ full: f.full }"
                         :style="def.formFields.length === 1 ? 'grid-column: 1 / -1;' : ''">
                        <label>{{ f.label }} <span v-if="esObligatorio(f)" style="color:var(--color-danger);">*</span></label>

                        <input v-if="f.type === 'text' || f.type === 'email'"
                               v-model="formData[f.name]" :type="f.type" class="input"
                               :required="f.required && !(editing && f.onlyOnCreate)"
                               :maxlength="f.max"
                               :readonly="editing && f.onlyOnCreate" />

                        <input v-else-if="f.type === 'number'"
                               v-model="formData[f.name]" type="number" class="input"
                               :step="f.step"
                               :required="esObligatorio(f) && !(editing && f.onlyOnCreate)"
                               :readonly="editing && f.onlyOnCreate"
                               :disabled="f.deshabilitado?.(formData)" />

                        <input v-else-if="f.type === 'date'"
                               v-model="formData[f.name]" type="date" class="input"
                               :required="esObligatorio(f)" />

                        <textarea v-else-if="f.type === 'textarea'"
                                  v-model="formData[f.name]" class="textarea" :required="f.required" />

                        <SelectBuscador v-else-if="f.type === 'select'"
                                        v-model="formData[f.name]"
                                        :opciones="f.options.map(o => ({ value: o.value, etiqueta: o.label }))"
                                        :deshabilitado="soloLectura" />

                        <label v-else-if="f.type === 'checkbox'"
                               style="display:flex;align-items:center;gap:8px;font-weight:400;">
                            <input type="checkbox" v-model="formData[f.name]" style="width:auto;margin:0;" />
                            <span>{{ f.checkboxLabel || 'Sí' }}</span>
                        </label>

                        <SelectBuscador v-else-if="f.type === 'select-async'"
                                        v-model="formData[f.name]"
                                        :opciones="opcionesAsync(f)"
                                        :opcion-vacia="esObligatorio(f) ? '' : '— Sin selección —'"
                                        :placeholder="esObligatorio(f) ? '— Elegir —' : '— Sin selección —'"
                                        :deshabilitado="soloLectura" />

                        <div v-if="f.hint" class="hint">{{ typeof f.hint === 'function' ? f.hint(formData) : f.hint }}</div>
                        <div v-if="errors[f.name]" class="error">{{ errors[f.name][0] }}</div>
                    </div>
                </div>
                </fieldset>
            </form>

            <!-- Lo que el registro tiene además de sus campos: los archivos de un contrato. -->
            <component :is="def.panelExtra" v-if="def.panelExtra && editing"
                       :fila="editing" :editable="puedeEditar(editing)" @cambio="load" />

            <template #footer>
                <template v-if="soloLectura">
                    <button type="button" class="btn btn-secondary" @click="formOpen = false">Cerrar</button>
                </template>
                <template v-else>
                    <button type="button" class="btn btn-secondary" @click="formOpen = false">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="save" :disabled="saving">
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </BaseModal>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { catalogoService, listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import http, { extractError } from '@/services/http';
import { debounce } from '@/composables/useFormat';
import { ENTITY_DEFS } from './definitions';
import BasePager from '@/components/BasePager.vue';
import BaseModal from '@/components/BaseModal.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import IconLib from '@/components/IconLib.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';
import ThOrden from '@/components/ThOrden.vue';

const route = useRoute();
const auth = useAuthStore();
const toast = useToast();

const def = computed(() => ENTITY_DEFS[route.params.slug] || null);
const service = computed(() => def.value ? catalogoService(def.value.endpoint) : null);

const state = reactive({ search: '', page: 1, per_page: 20, archivados: false });
const orden = reactive({ by: '', dir: 'asc' });
const rows = ref([]);
const total = ref(0);
const loading = ref(false);

const formOpen = ref(false);
const editing = ref(null);
const formData = reactive({});
const errors = ref({});
const saving = ref(false);
const asyncOptions = reactive({});

const soloLectura = ref(false);

const formTitle = computed(() => editing.value
    ? `${soloLectura.value ? 'Ver' : 'Editar'} — ${def.value?.tituloFila?.(editing.value) || def.value?.title}`
    : `Nuevo — ${def.value?.title}`);

/**
 * La columna que abre el registro: la marcada como `principal` o, si no hay,
 * la primera que no sea el id.
 */
const columnaPrincipal = computed(() => {
    const cols = def.value?.columns || [];
    return cols.find(c => c.principal)
        || cols.find(c => c.key !== 'id' && c.key !== def.value.keyField)
        || cols[0];
});

/**
 * Abrir desde el nombre. Por defecto lleva a editar si el usuario puede; un
 * catálogo puede pedir que siempre muestre el registro, como los contratos.
 */
function abrir(r) {
    openEdit(r, def.value.abrirEnConsulta || !puedeEditar(r));
}

/**
 * Quién edita cada fila. Por defecto, el administrador; un catálogo puede
 * decidirlo por fila, como los contratos, que edita quien tiene escritura
 * sobre su rama.
 */
function puedeEditar(r) {
    return def.value.editable ? def.value.editable(r) : auth.canAdminEstructura;
}

/** Opciones de un catálogo ya cargado, como las espera el selector con buscador. */
function opcionesAsync(f) {
    return (asyncOptions[f.name] || []).map(o => ({
        value: o[f.valueKey],
        etiqueta: typeof f.labelKey === 'function' ? f.labelKey(o) : o[f.labelKey],
    }));
}

/** Un campo puede ser obligatorio según lo que se cargó en otro. */
function esObligatorio(f) {
    return typeof f.required === 'function' ? f.required(formData) : !!f.required;
}

const onSearch = debounce(() => { state.page = 1; load(); }, 300);

/**
 * Filtros del catálogo. Los de la estructura (Gerencia de Área, Gerencia)
 * ofrecen sólo los nodos que caen dentro de lo elegido arriba, y al cambiar
 * uno se descartan los de abajo. Los demás son combos de otro catálogo, listas
 * fijas, fechas o importes.
 */
const filtros = reactive({});
const verFiltros = ref(false);
const nodosEstructura = ref([]);
const opcionesCatalogo = reactive({});
const NIVEL_NUM = { gerencia_area: 1, gerencia: 2, contrato: 3 };

const filtrosActivos = computed(() =>
    Object.values(filtros).filter(v => v !== '' && v !== null && v !== undefined).length);

function nodosParaFiltro(f) {
    const arriba = (def.value.filtros || [])
        .filter(o => o.type === 'estructura' && o.nivel < f.nivel && filtros[o.param]);
    const padreDe = new Map(nodosEstructura.value.map(n => [String(n.sector_id), n.dependencia_id]));
    const ancestros = (n) => {
        const out = [];
        let p = n.dependencia_id;
        while (p !== null && p !== undefined && !out.includes(String(p))) {
            out.push(String(p));
            p = padreDe.get(String(p));
        }
        return out;
    };
    return nodosEstructura.value
        .filter(n => NIVEL_NUM[n.nivel] === f.nivel)
        .filter(n => arriba.every(o => ancestros(n).includes(String(filtros[o.param]))))
        .sort((a, b) => String(a.nombre).localeCompare(String(b.nombre)));
}

function opcionesFiltro(f) {
    if (f.type === 'estructura') {
        return nodosParaFiltro(f).map(n => ({ value: n.sector_id, etiqueta: n.nombre }));
    }
    if (f.type === 'select-async') {
        return (opcionesCatalogo[f.param] || []).map(o => ({
            value: o[f.valueKey],
            etiqueta: typeof f.labelKey === 'function' ? f.labelKey(o) : o[f.labelKey],
        }));
    }
    return (f.options || []).map(o => ({ value: o.value, etiqueta: o.label }));
}

function aplicarFiltro(f) {
    if (f.type === 'estructura') {
        for (const o of def.value.filtros || []) {
            if (o.type === 'estructura' && o.nivel > f.nivel) filtros[o.param] = '';
        }
    }
    state.page = 1;
    load();
}

function limpiarFiltros() {
    for (const k of Object.keys(filtros)) filtros[k] = '';
    state.page = 1;
    load();
}

async function prepararFiltros() {
    for (const k of Object.keys(filtros)) delete filtros[k];
    for (const k of Object.keys(opcionesCatalogo)) delete opcionesCatalogo[k];
    // El panel arranca abierto: a la vista, los filtros se usan.
    verFiltros.value = true;
    const lista = def.value?.filtros;
    if (!lista) return;
    for (const f of lista) filtros[f.param] = '';
    // Cada catálogo se pide una vez aunque lo usen varios filtros.
    const pedidos = {};
    if (lista.some(f => f.type === 'estructura')) {
        pedidos.sectores = listAll('sectores').catch(() => []);
        nodosEstructura.value = await pedidos.sectores;
    }
    for (const f of lista.filter(f => f.type === 'select-async')) {
        pedidos[f.endpoint] ??= listAll(f.endpoint).catch(() => []);
        opcionesCatalogo[f.param] = await pedidos[f.endpoint];
    }
}

watch(() => route.params.slug, () => {
    state.search = ''; state.page = 1; state.archivados = false;
    orden.by = ''; orden.dir = 'asc';
    rows.value = []; total.value = 0;
    prepararFiltros();
    if (def.value) load();
});

async function load() {
    if (!service.value) return;
    loading.value = true;
    try {
        const res = await service.value.list({
            page: state.page, per_page: state.per_page,
            ...(state.search ? { search: state.search } : {}),
            ...(orden.by ? { order_by: orden.by, order_dir: orden.dir } : {}),
            ...(def.value.archivo && state.archivados ? { [def.value.archivo.param]: 1 } : {}),
            ...Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== '' && v !== null)),
        });
        rows.value = res.data || [];
        total.value = res.total || 0;
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar el listado.'));
    } finally {
        loading.value = false;
    }
}

function goto(p) { state.page = p; load(); }

/**
 * El orden lo resuelve el servidor: el listado viene por página y ordenar sólo
 * la página que se ve mostraría cualquier cosa.
 */
function ordenarPor(campo) {
    if (orden.by === campo) {
        orden.dir = orden.dir === 'asc' ? 'desc' : 'asc';
    } else {
        orden.by = campo;
        orden.dir = 'asc';
    }
    state.page = 1;
    load();
}

/** Valor inicial de un campo vacío, según su tipo. */
function VACIO_POR_TIPO(f) {
    if (f.type === 'select-async') return null;
    if (f.type === 'checkbox')     return f.default ?? true;
    if (f.default !== undefined)   return f.default;
    return '';
}

async function loadAsyncOptions() {
    asyncOptions && Object.keys(asyncOptions).forEach(k => delete asyncOptions[k]);
    // Varios campos pueden usar el mismo catálogo (dos responsables): se pide una vez.
    const pedidos = {};
    for (const f of def.value.formFields) {
        if (f.type === 'select-async') {
            pedidos[f.endpoint] ??= listAll(f.endpoint).catch(() => []);
            asyncOptions[f.name] = await pedidos[f.endpoint];
        }
    }
}

async function openNew() {
    editing.value = null;
    soloLectura.value = false;
    errors.value = {};
    for (const k of Object.keys(formData)) delete formData[k];
    for (const f of def.value.formFields) {
        formData[f.name] = VACIO_POR_TIPO(f);
    }
    await loadAsyncOptions();
    formOpen.value = true;
}

async function openEdit(r, consulta = false) {
    editing.value = r;
    soloLectura.value = consulta;
    errors.value = {};
    for (const k of Object.keys(formData)) delete formData[k];
    for (const f of def.value.formFields) {
        formData[f.name] = r[f.name] ?? VACIO_POR_TIPO(f);
    }
    await loadAsyncOptions();
    formOpen.value = true;
}

async function save() {
    if (soloLectura.value) return;
    errors.value = {};
    saving.value = true;
    try {
        const payload = { ...formData };
        // No mandar el legajo en update si onlyOnCreate
        if (editing.value) {
            for (const f of def.value.formFields) {
                if (f.onlyOnCreate) delete payload[f.name];
            }
            await service.value.update(editing.value[def.value.keyField], payload);
            toast.success('Registro actualizado.');
        } else {
            await service.value.create(payload);
            toast.success('Registro creado.');
        }
        formOpen.value = false;
        load();
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar.'));
        }
    } finally {
        saving.value = false;
    }
}

const confirmRef = ref(null);
async function remove(r) {
    const ok = await confirmRef.value.show({
        title: 'Eliminar',
        message: `¿Eliminar el registro #${r[def.value.keyField]}?`,
        confirmText: 'Eliminar',
        danger: true,
    });
    if (!ok) return;
    try {
        await service.value.remove(r[def.value.keyField]);
        toast.success('Eliminado.');
        load();
    } catch (err) {
        if (err?.response?.status === 409) {
            // El motivo está en `detalles`: sin él, el aviso no dice qué lo impide.
            const detalles = err.response.data?.detalles ?? [];
            toast.error([extractError(err, 'No se puede eliminar (hay dependencias).'), ...detalles].join(' '));
        } else {
            toast.error(extractError(err, 'No se pudo eliminar.'));
        }
    }
}

/**
 * Archivar saca el registro de los listados sin borrar nada; desarchivar lo
 * devuelve. Qué implica cada uno lo explica el catálogo.
 */
async function archivar(r) {
    const archivar = !def.value.archivo.archivado(r);
    const nombre = def.value.tituloFila?.(r) || r.nombre || `#${r[def.value.keyField]}`;
    const ok = await confirmRef.value.show({
        title: archivar ? 'Archivar' : 'Desarchivar',
        message: archivar
            ? `¿Archivar «${nombre}»? ${def.value.archivo.alArchivar}`
            : `¿Desarchivar «${nombre}»? Vuelve a aparecer en los listados.`,
        confirmText: archivar ? 'Archivar' : 'Desarchivar',
    });
    if (!ok) return;
    try {
        await http.post(`/${def.value.endpoint}/${r[def.value.keyField]}/archivar`, { archivar });
        toast.success(archivar ? 'Archivado.' : 'Desarchivado.');
        load();
    } catch (err) {
        toast.error(extractError(err, 'No se pudo archivar.'));
    }
}

onMounted(() => { prepararFiltros(); if (def.value) load(); });
</script>
