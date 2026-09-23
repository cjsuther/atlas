<template>
    <div>
        <h1 class="page-title">{{ isEdit ? 'Editar' : 'Nuevo' }} expediente</h1>
        <p class="page-subtitle">
            Todo expediente se imputa a una cuenta operativa de la estructura
        </p>

        <ExpedienteForm :id="route.params.id || null"
                        @guardado="alGuardar" @cancelar="router.back()" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ExpedienteForm from '@/components/ExpedienteForm.vue';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);

function alGuardar(expediente) {
    router.replace({ name: 'expedientes-detalle', params: { id: expediente.id } });
}
</script>
