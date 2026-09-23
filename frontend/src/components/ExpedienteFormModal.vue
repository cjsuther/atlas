<template>
    <BaseModal v-model="abierto" title="Nuevo expediente" size="large">
        <ExpedienteForm v-if="abierto" en-modal @guardado="alGuardar" @cancelar="abierto = false" />
        <template #footer><span /></template>
    </BaseModal>
</template>

<script setup>
import { ref } from 'vue';
import BaseModal from './BaseModal.vue';
import ExpedienteForm from './ExpedienteForm.vue';

/**
 * Alta de un expediente sin salir de donde se estaba: se abre desde el
 * selector de expedientes de un movimiento y, al guardar, el expediente nuevo
 * queda elegido.
 */
const emit = defineEmits(['guardado']);

const abierto = ref(false);

function show() {
    abierto.value = true;
}

function alGuardar(expediente) {
    abierto.value = false;
    emit('guardado', expediente);
}

defineExpose({ show });
</script>
