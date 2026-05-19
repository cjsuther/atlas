<template>
    <BaseModal v-model="open" :title="title">
        <p style="margin:0;">{{ message }}</p>
        <template #footer>
            <button type="button" class="btn btn-secondary" @click="resolve(false)">Cancelar</button>
            <button type="button" :class="['btn', danger ? 'btn-danger' : 'btn-primary']" @click="resolve(true)">
                {{ confirmText }}
            </button>
        </template>
    </BaseModal>
</template>

<script setup>
import { ref } from 'vue';
import BaseModal from './BaseModal.vue';

const open = ref(false);
const title = ref('');
const message = ref('');
const confirmText = ref('Confirmar');
const danger = ref(false);
let resolver = null;

function resolve(v) {
    open.value = false;
    if (resolver) {
        const r = resolver; resolver = null;
        r(v);
    }
}

function show(opts = {}) {
    title.value = opts.title || 'Confirmar';
    message.value = opts.message || '¿Está seguro?';
    confirmText.value = opts.confirmText || 'Confirmar';
    danger.value = !!opts.danger;
    open.value = true;
    return new Promise((res) => { resolver = res; });
}

defineExpose({ show });
</script>
