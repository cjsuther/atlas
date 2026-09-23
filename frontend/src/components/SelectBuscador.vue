<template>
    <div class="select-buscador" ref="raiz">
        <button type="button" class="select select-buscador-valor" :class="{ vacio: !seleccionada }"
                :disabled="deshabilitado" @click="alternar">
            <span>{{ seleccionada ? seleccionada.etiqueta : placeholder }}</span>
            <span class="flecha-abajo">▾</span>
        </button>

        <div v-if="abierto" class="select-buscador-panel">
            <input v-if="conBuscador" ref="buscadorRef" v-model="busqueda" class="input" type="text"
                   :placeholder="placeholderBusqueda" @keydown.esc.prevent="cerrar" />
            <ul class="select-buscador-lista">
                <li v-if="opcionVacia">
                    <button type="button" class="opcion" @click="elegir(valorVacio)">{{ opcionVacia }}</button>
                </li>
                <li v-for="o in filtradas" :key="o.value">
                    <button type="button" class="opcion"
                            :class="{ activa: String(o.value) === String(modelValue), deshabilitada: o.deshabilitada }"
                            :disabled="o.deshabilitada"
                            @click="elegir(o.value)">
                        {{ o.etiqueta }}
                    </button>
                </li>
                <li v-if="!filtradas.length" class="sin-resultados">Sin resultados.</li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Selector con buscador: las listas largas —contratos, cuentas— no se pueden
 * recorrer a ojo, así que se escribe parte del texto y la lista se filtra.
 *
 * `opciones` son `{ value, etiqueta, deshabilitada? }`.
 */
const props = defineProps({
    modelValue:         { type: [Number, String, null], default: null },
    opciones:           { type: Array, default: () => [] },
    placeholder:        { type: String, default: 'Seleccionar…' },
    placeholderBusqueda:{ type: String, default: 'Buscar…' },
    /** Texto de la opción que deja el campo vacío. Si no se pasa, no se ofrece. */
    opcionVacia:        { type: String, default: '' },
    /** Qué valor emite esa opción: null en los formularios, '' en los filtros. */
    valorVacio:         { type: [Number, String, null], default: null },
    deshabilitado:      { type: Boolean, default: false },
    /**
     * A partir de cuántas opciones se muestra el buscador. En una lista de dos
     * o tres no aporta nada y sólo estorba.
     */
    umbralBusqueda:     { type: Number, default: 8 },
});
const emit = defineEmits(['update:modelValue']);

const raiz = ref(null);
const buscadorRef = ref(null);
const abierto = ref(false);
const busqueda = ref('');

const conBuscador = computed(() => props.opciones.length >= props.umbralBusqueda);

const seleccionada = computed(() =>
    props.opciones.find(o => String(o.value) === String(props.modelValue)) || null);

const filtradas = computed(() => {
    const t = busqueda.value.trim().toLowerCase();
    if (!t) return props.opciones;
    return props.opciones.filter(o => String(o.etiqueta).toLowerCase().includes(t));
});

function alternar() {
    abierto.value = !abierto.value;
}

function cerrar() {
    abierto.value = false;
    busqueda.value = '';
}

function elegir(value) {
    emit('update:modelValue', value);
    cerrar();
}

watch(abierto, async (v) => {
    if (!v || !conBuscador.value) return;
    await nextTick();
    buscadorRef.value?.focus();
});

/** Un clic fuera cierra la lista. */
function alClickAfuera(ev) {
    if (abierto.value && raiz.value && !raiz.value.contains(ev.target)) cerrar();
}

onMounted(() => document.addEventListener('mousedown', alClickAfuera));
onBeforeUnmount(() => document.removeEventListener('mousedown', alClickAfuera));
</script>

<style scoped>
.select-buscador { position: relative; }

.select-buscador-valor {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    text-align: left;
    cursor: pointer;
}
.select-buscador-valor.vacio { color: var(--color-muted, #888); }
.flecha-abajo { opacity: 0.7; font-size: 12px; }

.select-buscador-panel {
    position: absolute;
    z-index: 30;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #d5d5d5);
    border-radius: 6px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
    padding: 6px;
}

.select-buscador-valor:disabled { cursor: not-allowed; opacity: 0.6; }

.select-buscador-lista {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    max-height: 240px;
    overflow-y: auto;
}

.opcion {
    display: block;
    width: 100%;
    text-align: left;
    background: none;
    border: 0;
    padding: 6px 8px;
    font-size: 13px;
    border-radius: 4px;
    cursor: pointer;
    white-space: pre-wrap;
}
.opcion:hover { background: var(--color-surface-alt, rgba(0, 0, 0, 0.05)); }
.opcion.activa { background: var(--color-primary-2, rgba(0, 0, 0, 0.08)); font-weight: 600; }
.opcion.deshabilitada { color: var(--color-muted, #888); cursor: not-allowed; }
.opcion.deshabilitada:hover { background: none; }

.sin-resultados {
    padding: 8px;
    font-size: 13px;
    color: var(--color-muted, #888);
}
</style>
