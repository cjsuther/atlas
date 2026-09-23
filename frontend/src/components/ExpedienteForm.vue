<template>
    <div>
        <div v-if="loading" class="empty-state"><span class="loader dark" /> Cargando…</div>

        <form v-else :class="{ card: !enModal }" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label>Nº de expediente *</label>
                    <ExpedienteInput v-model="data.nro_expediente" />
                    <div v-if="errors.nro_expediente" class="error">{{ errors.nro_expediente[0] }}</div>
                </div>

                <div class="field">
                    <label>Cuenta *</label>
                    <SelectBuscador v-model="data.cuenta_operativa_id" :opciones="opcionesCuenta"
                                    placeholder="Elegir cuenta…"
                                    placeholder-busqueda="Buscar por nombre de cuenta o nodo…" />
                    <div class="hint">
                        El expediente va contra esa cuenta. La rama a la que pertenece sale
                        de dónde cuelga.
                    </div>
                    <div v-if="errors.cuenta_operativa_id" class="error">{{ errors.cuenta_operativa_id[0] }}</div>
                </div>
            </div>

            <div class="modal-footer" style="border-top:1px solid var(--color-border);margin-top:18px;padding:14px 0 0;">
                <button type="button" class="btn btn-secondary" @click="$emit('cancelar')">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="loader" /> {{ saving ? 'Guardando…' : 'Guardar' }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { expedientesService } from '@/services/expedientes';
import { useToast } from '@/composables/useToast';
import { extractError } from '@/services/http';
import ExpedienteInput from '@/components/ExpedienteInput.vue';
import SelectBuscador from '@/components/SelectBuscador.vue';

/**
 * Alta y edición de un expediente: su número y la cuenta contra la que va.
 *
 * Vive como componente porque se usa en su propia pantalla y también en una
 * ventana, cuando se da de alta sin salir de la carga de un movimiento.
 */
const props = defineProps({
    /** Id del expediente a editar; vacío es un alta. */
    id:      { type: [Number, String], default: null },
    /** En una ventana el formulario no lleva el marco de tarjeta. */
    enModal: { type: Boolean, default: false },
});
const emit = defineEmits(['guardado', 'cancelar']);

const toast = useToast();

const isEdit = computed(() => !!props.id);
const loading = ref(true);
const saving = ref(false);
const errors = ref({});

const data = reactive({
    nro_expediente: '',
    cuenta_operativa_id: null,
});

const arbol = ref(null);

/**
 * Las cuentas del árbol, sangradas por nivel. El servidor marca cuáles puede
 * usar el usuario: el resto se ven, pero no se pueden elegir.
 */
const opcionesCuenta = computed(() => {
    const filas = [];
    const recorrer = (nodo, nivel) => {
        const sangria = '\u00A0\u00A0'.repeat(nivel);
        for (const c of nodo.cuentas || []) {
            filas.push({
                value: c.id,
                etiqueta: `${sangria}${nodo.nombre} · ${c.nombre}`,
                deshabilitada: !c.permitida,
            });
        }
        for (const h of nodo.hijos || []) recorrer(h, nivel + 1);
    };
    if (arbol.value) recorrer(arbol.value, 0);
    return filas;
});

async function loadExpediente() {
    if (!isEdit.value) return;
    const res = await expedientesService.get(props.id);
    data.nro_expediente = res.data.nro_expediente;
    data.cuenta_operativa_id = res.data.cuenta_operativa_id;
}

async function submit() {
    errors.value = {};
    saving.value = true;
    try {
        const payload = { ...data };
        const res = isEdit.value
            ? await expedientesService.update(props.id, payload)
            : await expedientesService.create(payload);
        toast.success(isEdit.value ? 'Expediente actualizado.' : 'Expediente creado.');
        emit('guardado', res.data);
    } catch (err) {
        if (err?.response?.status === 422 && err.response.data?.errors) {
            errors.value = err.response.data.errors;
            toast.error('Hay errores de validación en el formulario.');
        } else {
            toast.error(extractError(err, 'No se pudo guardar.'));
        }
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        arbol.value = await expedientesService.arbolEstructura();
        await loadExpediente();
    } catch (err) {
        toast.error(extractError(err, 'Error al cargar el formulario.'));
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
.hint { font-size: 12px; color: var(--color-muted, #888); margin-top: 4px; }
</style>
