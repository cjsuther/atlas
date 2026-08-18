<template>
    <details v-if="canSee" class="collapsible">
        <summary @click="loadOnce">
            Historial de cambios
            <span v-if="items.length" style="font-weight:400;opacity:0.9;font-size:13px;">
                ({{ items.length }} eventos)
            </span>
        </summary>
        <div class="collapsible-body">
            <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>
            <div v-else-if="!items.length" class="empty-state">Sin registros.</div>
            <table v-else class="atlas-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Tipo</th>
                        <th>Campo</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="h in items" :key="h.id">
                        <td>{{ fmtDateTime(h.fecha) }}</td>
                        <td>{{ h.usuario }}</td>
                        <td><span :class="['badge', badge(h.tipo_cambio)]">{{ h.tipo_cambio }}</span></td>
                        <td>{{ h.campo_modificado || '—' }}</td>
                        <td>{{ h.valor_anterior ?? '—' }}</td>
                        <td>{{ h.valor_nuevo ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>
</template>

<script setup>
import { computed, ref } from 'vue';
import { historialService } from '@/services/historial';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import { fmtDateTime } from '@/composables/useFormat';

const props = defineProps({
    tabla: { type: String, required: true },   // 'contratos_principal' | 'contratos_ejecucion'
    id:    { type: [Number, String], required: true },
});

const auth = useAuthStore();
const toast = useToast();
const items = ref([]);
const loading = ref(false);
let loaded = false;

// El backend ya recorta el historial al alcance del usuario.
const canSee = computed(() => auth.isAuthenticated);

async function loadOnce() {
    if (loaded || loading.value) return;
    loading.value = true;
    try {
        const res = await historialService.forRecord(props.tabla, props.id, { per_page: 200 });
        items.value = res.data || [];
        loaded = true;
    } catch (err) {
        toast.error(extractError(err, 'No se pudo cargar el historial.'));
    } finally {
        loading.value = false;
    }
}

function badge(tipo) {
    if (tipo === 'creacion') return 'badge-success';
    if (tipo === 'baja') return 'badge-danger';
    return 'badge-info';
}
</script>
