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
                    <router-link v-if="auth.canEdit && !c.deleted_at"
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
                        <Field label="Cuenta" :value="c.cuenta_operativa?.nombre" />
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

        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { expedientesService } from '@/services/expedientes';
import { useAuthStore } from '@/stores/auth';
import { fmtMoney } from '@/composables/useFormat';
import IconLib from '@/components/IconLib.vue';
import Field from '@/components/DetailField.vue';

const route = useRoute();
const auth = useAuthStore();

const c = ref(null);
const loading = ref(true);

async function refrescar() {
    try {
        const r = await expedientesService.get(route.params.id, { mostrar_baja: '1' });
        c.value = r.data;
    } catch { /* no-op */ }
}

onMounted(async () => {
    await refrescar();
    loading.value = false;
});
</script>

