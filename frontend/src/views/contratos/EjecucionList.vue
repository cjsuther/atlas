<template>
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 class="page-title">Contratos</h1>
                <p class="page-subtitle">
                    Contratos por gerencia (CP / CIT / Convenio Específico)
                    <template v-if="!auth.isAdminSistema && auth.gerencia"> · {{ auth.gerencia }}</template>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn btn-secondary" @click="exportar">
                    <IconLib name="download" /> Exportar Excel
                </button>
                <router-link v-if="auth.canEdit" :to="{ name: 'contratos-ejecucion-nuevo' }" class="btn btn-primary">
                    <IconLib name="plus" /> Nuevo
                </router-link>
            </div>
        </div>

        <div class="filters-panel">
            <div class="filters-grid">
                <div class="field">
                    <label>Buscar</label>
                    <input v-model="filters.search" type="text" class="input"
                           placeholder="Proyecto, expediente, descripción..." @input="onFilter" />
                </div>
                <div class="field">
                    <label>Estado</label>
                    <select v-model="filters.estado_id" class="select" @change="onFilter">
                        <option value="">Todos</option>
                        <option v-for="e in estados" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>Tipo</label>
                    <select v-model="filters.tipo_contrato_id" class="select" @change="onFilter">
                        <option value="">Todos</option>
                        <option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.sigla }} — {{ t.nombre }}</option>
                    </select>
                </div>
                <div v-if="auth.isAdminSistema || auth.isAdminGerencia" class="field">
                    <label>Gerencia de Área</label>
                    <select v-model="filters.gerencia_area_id" class="select" @change="onFilter">
                        <option value="">Todas</option>
                        <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                    </select>
                </div>
                <div v-if="auth.isAdminSistema || auth.isAdminGerencia" class="field">
                    <label>Gerencia</label>
                    <select v-model="filters.gerencia_id" class="select" @change="onFilter">
                        <option value="">Todas</option>
                        <option v-for="g in gerencias" :key="g.id" :value="g.id">{{ g.nombre }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>UVT</label>
                    <select v-model="filters.uvt_id" class="select" @change="onFilter">
                        <option value="">Todas</option>
                        <option v-for="u in uvts" :key="u.uvt_id" :value="u.uvt_id">{{ u.siglas }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>Moneda</label>
                    <select v-model="filters.moneda" class="select" @change="onFilter">
                        <option value="">Todas</option>
                        <option>Peso</option>
                        <option>Dólar</option>
                        <option>Euro</option>
                        <option>Otro</option>
                    </select>
                </div>
                <div class="field">
                    <label>Solo vencidos</label>
                    <select v-model="filters.vencidos" class="select" @change="onFilter">
                        <option :value="''">No</option>
                        <option :value="'1'">Sí</option>
                    </select>
                </div>
                <div v-if="auth.isAdminSistema" class="field">
                    <label>Mostrar dados de baja</label>
                    <select v-model="filters.mostrar_baja" class="select" @change="onFilter">
                        <option :value="''">No</option>
                        <option :value="'1'">Sí</option>
                    </select>
                </div>
            </div>
        </div>

        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!rows.length" class="empty-state">No hay contratos de ejecución.</div>

        <div v-else class="table-wrapper">
            <table class="atlas-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Expediente</th>
                        <th>Tipo</th>
                        <th>Proyecto</th>
                        <th>Estado</th>
                        <th>Gerencia</th>
                        <th>UVT</th>
                        <th>F. Inicio</th>
                        <th>F. Venc.</th>
                        <th>Ejecutado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :class="{ deleted: r.deleted_at }">
                        <td>{{ r.id }}</td>
                        <td>{{ r.nro_expediente }}</td>
                        <td>{{ r.tipo_contrato?.sigla }}</td>
                        <td>{{ r.nombre_proyecto }}</td>
                        <td><span :class="['badge', badgeForEstado(r.estado)]">{{ r.estado?.nombre || '—' }}</span></td>
                        <td>
                            <div>{{ r.gerencia?.nombre || '—' }}</div>
                            <div v-if="r.gerencia?.gerencia_area" style="font-size:11px;color:var(--color-muted);">
                                {{ r.gerencia.gerencia_area.nombre }}
                            </div>
                        </td>
                        <td>{{ r.uvt?.siglas || '—' }}</td>
                        <td>{{ fmtDate(r.fecha_inicio) }}</td>
                        <td>{{ fmtDate(r.fecha_vencimiento) }}</td>
                        <td>
                            <div style="font-size:12px;line-height:1.3;">
                                <div><span style="color:var(--color-muted);">Ing.:</span> {{ r.moneda }} {{ fmtMoney(r.monto_ejecutado_ingresos) }}</div>
                                <div><span style="color:var(--color-muted);">Gtos.:</span> {{ r.moneda }} {{ fmtMoney(r.monto_ejecutado_gastos) }}</div>
                            </div>
                        </td>
                        <td class="actions">
                            <router-link :to="{ name: 'contratos-ejecucion-detalle', params: { id: r.id } }">
                                <button><IconLib name="eye" :size="14" /></button>
                            </router-link>
                            <router-link v-if="auth.canEdit && !r.deleted_at"
                                         :to="{ name: 'contratos-ejecucion-editar', params: { id: r.id } }">
                                <button><IconLib name="edit" :size="14" /></button>
                            </router-link>
                            <button v-if="auth.canEdit && !r.deleted_at" class="danger" @click="darBaja(r)">
                                <IconLib name="trash" :size="14" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <BasePager :page="page" :per-page="perPage" :total="total" @change="goto" />
        </div>

        <ConfirmDialog ref="confirmRef" />
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { contratosEjecucionService } from '@/services/contratosEjecucion';
import { listAll } from '@/services/catalogos';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDate, fmtMoney, badgeForEstado, debounce } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import BasePager from '@/components/BasePager.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

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
    estado_id: '',
    tipo_contrato_id: '',
    gerencia_area_id: '',
    gerencia_id: '',
    uvt_id: '',
    moneda: '',
    vencidos: '',
    mostrar_baja: '',
});

const estados = ref([]);
const tipos = ref([]);
const uvts = ref([]);
const areas = ref([]);
const gerencias = ref([]);

const onFilter = debounce(() => { page.value = 1; load(); }, 300);

async function load() {
    loading.value = true;
    try {
        const params = { page: page.value, per_page: perPage.value };
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) params[k] = v;
        }
        const res = await contratosEjecucionService.list(params);
        rows.value = res.data || [];
        total.value = res.total || 0;
        perPage.value = res.per_page || 20;
    } catch (err) {
        toast.error(extractError(err, 'No se pudieron cargar los contratos.'));
    } finally {
        loading.value = false;
    }
}

function goto(p) { page.value = p; load(); }

async function darBaja(r) {
    const ok = await confirmRef.value.show({
        title: 'Dar de baja',
        message: `¿Confirma dar de baja el contrato #${r.id}? Queda registrado en el historial.`,
        confirmText: 'Dar de baja',
        danger: true,
    });
    if (!ok) return;
    try {
        await contratosEjecucionService.remove(r.id);
        toast.success('Contrato dado de baja.');
        load();
    } catch (err) {
        toast.error(extractError(err, 'No se pudo dar de baja.'));
    }
}

async function exportar() {
    try {
        const params = {};
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) params[k] = v;
        }
        await contratosEjecucionService.exportExcel(params);
    } catch (err) {
        toast.error(extractError(err, 'No se pudo exportar.'));
    }
}

onMounted(async () => {
    try {
        const [e, t, u, ga, g] = await Promise.all([
            listAll('estados-ejecucion'),
            listAll('tipos-contrato-ejecucion'),
            listAll('uvt'),
            listAll('gerencias-area'),
            listAll('gerencias'),
        ]);
        estados.value = e; tipos.value = t; uvts.value = u;
        areas.value = ga; gerencias.value = g;
    } catch { /* no-op */ }
    load();
});
</script>
