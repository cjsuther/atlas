<template>
    <div>
        <div>
            <h1 class="page-title">Log de actividades</h1>
            <p class="page-subtitle">
                Quién cargó, pidió, aprobó o rechazó cada movimiento, cuándo y con qué observación
                <template v-if="!auth.veTodo"> · {{ auth.alcanceLabel }}</template>
            </p>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="filters.search" type="text" class="input"
                           placeholder="Movimiento, expediente, cuenta u observación…" @input="onFilterDebounced" />
                </div>
                <div class="field">
                    <label>Usuario</label>
                    <input v-model="filters.usuario" type="text" class="input"
                           placeholder="Usuario o nombre…" @input="onFilterDebounced" />
                </div>
                <div class="field">
                    <label>Actividad</label>
                    <SelectBuscador v-model="filters.accion" :opciones="acciones"
                                    opcion-vacia="Todas" valor-vacio="" placeholder="Todas"
                                    @update:model-value="onFilter" />
                </div>
                <div class="field">
                    <label>Desde</label>
                    <input v-model="filters.desde" type="date" class="input" @change="onFilter" />
                </div>
                <div class="field">
                    <label>Hasta</label>
                    <input v-model="filters.hasta" type="date" class="input" @change="onFilter" />
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">No hay actividades registradas.</div>

        <template v-else>
            <div class="table-wrapper">
                <table class="atlas-table">
                    <thead>
                        <tr>
                            <th>Fecha y hora</th>
                            <th>Usuario</th>
                            <th>Actividad</th>
                            <th>Cuenta</th>
                            <th>Detalle</th>
                            <th>Observación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in rows" :key="a.id">
                            <td style="white-space:nowrap;">{{ fmtDateTime(a.fecha) }}</td>
                            <td>
                                <div>{{ a.usuario_nombre || a.usuario }}</div>
                                <div v-if="a.usuario_nombre" style="font-size:11px;color:var(--color-muted);">{{ a.usuario }}</div>
                            </td>
                            <td style="white-space:nowrap;">
                                <span :class="['badge', badge(a.accion)]">{{ a.accion_label }}</span>
                            </td>
                            <td>
                                <router-link v-if="a.cuenta_operativa_id && a.cuenta_nombre"
                                             :to="{ name: 'cuenta-detalle', params: { id: a.cuenta_operativa_id } }">
                                    {{ a.cuenta_nombre }}
                                </router-link>
                                <span v-else style="color:var(--color-muted);">—</span>
                            </td>
                            <td style="white-space:normal;max-width:420px;">{{ a.descripcion }}</td>
                            <td style="white-space:pre-wrap;max-width:320px;">{{ a.observacion || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <BasePager :page="page" :per-page="perPage" :total="total" @change="goto" />
        </template>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { actividadesService } from '@/services/aprobaciones';
import { extractError } from '@/services/http';
import { useToast } from '@/composables/useToast';
import { fmtDateTime, debounce } from '@/composables/useFormat';
import BasePager from '@/components/BasePager.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';

const auth = useAuthStore();
const toast = useToast();

const filters = reactive({ search: '', usuario: '', accion: '', desde: '', hasta: '' });
const rows = ref([]);
const acciones = ref([]);
const loading = ref(false);
const page = ref(1);
const perPage = 50;
const total = ref(0);

/** El color dice si fue una decisión o un pedido. */
function badge(accion) {
    if (accion === 'aprobacion') return 'badge-success';
    if (accion === 'rechazo') return 'badge-danger';
    if (accion === 'pedido_baja' || accion === 'baja') return 'badge-warning';
    return 'badge-info';
}

async function load() {
    loading.value = true;
    try {
        const params = { page: page.value, per_page: perPage };
        for (const [k, v] of Object.entries(filters)) {
            if (v) params[k] = v;
        }
        const r = await actividadesService.list(params);
        rows.value = r.data || [];
        total.value = r.total || 0;
        acciones.value = r.acciones || [];
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar el log de actividades.'));
    } finally {
        loading.value = false;
    }
}

function onFilter() {
    page.value = 1;
    load();
}
const onFilterDebounced = debounce(onFilter, 350);

function goto(n) {
    page.value = n;
    load();
}

onMounted(load);
</script>
