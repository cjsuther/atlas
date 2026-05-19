<template>
    <Teleport to="body">
        <div v-if="modelValue" class="modal-backdrop" @click.self="cancel">
            <div :class="['modal', size === 'large' ? 'large' : '']">
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
        </div>
    </Teleport>
</template>

<script setup>
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'cancel']);
function cancel() {
    emit('update:modelValue', false);
    emit('cancel');
}
</script>
