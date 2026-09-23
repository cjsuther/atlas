<template>
    <th :class="['ordenable', { derecha }]" @click="$emit('ordenar', campo)">
        <slot />
        <span class="flecha">{{ flecha }}</span>
    </th>
</template>

<script setup>
import { computed } from 'vue';

/**
 * Encabezado de tabla que ordena al hacer clic. El estado del orden lo lleva
 * la tabla —en el navegador con `useOrdenTabla`, o en el servidor— y acá sólo
 * se muestra la flecha de la columna activa.
 */
const props = defineProps({
    campo:   { type: String, required: true },
    orden:   { type: Object, required: true },  // { by, dir }
    derecha: { type: Boolean, default: false },
});
defineEmits(['ordenar']);

const flecha = computed(() => {
    if (props.orden.by !== props.campo) return '';
    return props.orden.dir === 'asc' ? '▲' : '▼';
});
</script>
