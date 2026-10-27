<template>
    <div>
        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
        <div v-else-if="!c" class="empty-state">Expediente no encontrado.</div>

        <div v-else>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1 class="page-title">
                        <span v-if="c.deleted_at" class="badge badge-default">dado de baja</span>
                        Expediente {{ c.nro_expediente }}
                    </h1>
                    <p class="page-subtitle">
                        #{{ c.id }} · {{ c.cuenta_operativa?.ruta }}
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <router-link v-if="c.editable && !c.deleted_at"
                                 :to="{ name: 'expedientes-editar', params: { id: c.id } }"
                                 class="btn btn-primary">
                        <IconLib name="edit" /> Editar
                    </router-link>
                    <router-link :to="{ name: 'expedientes-movimientos', params: { id: c.id } }"
                                 class="btn btn-primary">
                        Movimientos
                    </router-link>
                    <router-link :to="{ name: 'expedientes' }" class="btn btn-secondary">Volver</router-link>
                </div>
            </div>

            <div class="card">
                <div class="detail-section">
                    <h4>Datos</h4>
                    <div class="detail-grid">
                        <Field label="Expediente" :value="c.nro_expediente" />
                        <Field label="Cuenta de origen" :value="c.cuenta_operativa?.nombre" />
                        <Field label="Ubicación en la estructura" :value="c.cuenta_operativa?.ruta" />
                        <Field label="Gerencia de Área" :value="c.gerencia_area?.nombre" />
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Movimientos relacionados</h4>
                    <div class="detail-grid">
                        <Field label="Ingresos" :value="fmtMoney(c.monto_ejecutado_ingresos)" />
                        <Field label="Gastos"   :value="fmtMoney(c.monto_ejecutado_gastos)" />
                        <Field label="Resultado (ing. − gtos.)" :value="fmtMoney(c.saldo)" />
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="detail-section">
                    <h4>Historial de cambios</h4>
                    <p class="hint" style="margin:0 0 10px;">
                        Quién hizo cada cambio y cuándo, en el expediente y en sus movimientos.
                    </p>
                    <div v-if="cargandoHistorial" class="empty-state"><span class="loader dark" /> Cargando…</div>
                    <div v-else-if="!historial.length" class="empty-state">Todavía no hay cambios registrados.</div>
                    <div v-else class="table-wrapper">
                        <table class="atlas-table">
                            <thead>
                                <tr>
                                    <th>Fecha y hora</th>
                                    <th>Usuario</th>
                                    <th>Sobre</th>
                                    <th>Acción</th>
                                    <th>Campo</th>
                                    <th>Antes</th>
                                    <th>Después</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="h in historial" :key="h.id">
                                    <td style="white-space:nowrap;">{{ fmtDateTime(h.fecha) }}</td>
                                    <td>
                                        <div>{{ h.usuario_nombre || h.usuario }}</div>
                                        <div v-if="h.usuario_nombre && h.usuario_nombre !== h.usuario"
                                             style="font-size:11px;color:var(--color-muted);">{{ h.usuario }}</div>
                                    </td>
                                    <td>{{ h.sobre }}</td>
                                    <td><span :class="['badge', ACCIONES[h.tipo_cambio]?.badge]">
                                        {{ ACCIONES[h.tipo_cambio]?.texto || h.tipo_cambio }}
                                    </span></td>
                                    <td>{{ h.campo || '—' }}</td>
                                    <td>{{ h.valor_anterior ?? '—' }}</td>
                                    <td>{{ h.valor_nuevo ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { expedientesService } from '@/services/expedientes';
import { fmtMoney, fmtDateTime } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import Field from '@/components/DetailField.vue';

const route = useRoute();

const c = ref(null);
const loading = ref(true);
const historial = ref([]);
const cargandoHistorial = ref(true);

const ACCIONES = {
    creacion:     { texto: 'Alta',         badge: 'badge-success' },
    modificacion: { texto: 'Modificación', badge: 'badge-info' },
    baja:         { texto: 'Baja',         badge: 'badge-danger' },
};

async function cargarHistorial() {
    cargandoHistorial.value = true;
    try {
        historial.value = await expedientesService.historial(route.params.id);
    } catch { historial.value = []; }
    finally { cargandoHistorial.value = false; }
}

async function refrescar() {
    try {
        const r = await expedientesService.get(route.params.id, { mostrar_baja: '1' });
        c.value = r.data;
    } catch { /* no-op */ }
}

onMounted(async () => {
    await refrescar();
    loading.value = false;
    if (c.value) cargarHistorial();
});
</script>

