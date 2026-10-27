<template>
    <Teleport to="body">
        <div v-if="modelValue" class="modal-backdrop"
             @mousedown.self="presionadoAfuera = true" @click.self="clicAfuera">
            <div :class="['modal', size === 'large' ? 'large' : '']"
                 @input="marcarCambio" @change="marcarCambio" @cambio-campo="marcarCambio">
                <div class="modal-header">
                    <h3>{{ title }}</h3>
                    <button type="button" class="close" @click="cancel" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <slot />
                </div>
                <div class="modal-footer">
                    <slot name="footer">
                        <button type="button" class="btn btn-secondary" @click="cancel">Cerrar</button>
                    </slot>
                </div>
            </div>

            <!-- Cerrar con datos cargados se confirma: un clic afuera sin querer
                 no tiene que costar todo lo escrito. -->
            <div v-if="preguntando" class="modal-confirmar-cierre" role="alertdialog" aria-modal="true">
                <div class="caja">
                    <h4>¿Cerrar sin guardar?</h4>
                    <p>Hay datos cargados en esta ventana. Si la cierra, se pierden.</p>
                    <div class="botones">
                        <button type="button" class="btn btn-secondary" @click="preguntando = false">
                            Seguir cargando
                        </button>
                        <button type="button" class="btn btn-danger" @click="cerrar">
                            Cerrar y perder los datos
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: '' },
    /** Pedir confirmación antes de cerrar si se cargó algo. */
    confirmarCierre: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue', 'cancel']);

/** Si el usuario escribió o eligió algo desde que se abrió la ventana. */
const conCambios = ref(false);
const preguntando = ref(false);
/** El clic empezó sobre el fondo: arrastrar desde un campo hasta afuera no cierra. */
const presionadoAfuera = ref(false);

watch(() => props.modelValue, (abierta) => {
    if (abierta) {
        conCambios.value = false;
        preguntando.value = false;
    }
});

/** Lo que se escribe para buscar en una lista no es un dato cargado. */
function marcarCambio(e) {
    if (e?.target?.closest?.('[data-sin-cambio]')) return;
    conCambios.value = true;
}

function clicAfuera() {
    const empezoAfuera = presionadoAfuera.value;
    presionadoAfuera.value = false;
    if (empezoAfuera) cancel();
}

function cancel() {
    if (props.confirmarCierre && conCambios.value) {
        preguntando.value = true;
        return;
    }
    cerrar();
}

function cerrar() {
    preguntando.value = false;
    emit('update:modelValue', false);
    emit('cancel');
}
</script>

<style scoped>
.modal-confirmar-cierre {
    position: fixed; inset: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(26, 46, 74, 0.35);
    z-index: 1001;
    padding: 20px;
}
.modal-confirmar-cierre .caja {
    background: var(--color-surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    max-width: 420px; width: 100%;
    padding: 20px;
}
.modal-confirmar-cierre h4 { margin: 0 0 8px; font-size: 16px; }
.modal-confirmar-cierre p { margin: 0 0 16px; color: var(--color-muted); font-size: 14px; }
.modal-confirmar-cierre .botones { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
</style>
