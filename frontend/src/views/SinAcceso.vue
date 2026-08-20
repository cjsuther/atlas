<template>
    <div class="sin-acceso">
        <div class="caja">
            <AtlasLogo :size="48" />
            <h1>Su usuario todavía no tiene permisos</h1>
            <p>
                Ingresó correctamente como
                <strong>{{ auth.user?.display_name || auth.user?.username }}</strong>,
                pero un administrador todavía no le asignó un rol ni una Gerencia de Área,
                así que por ahora no hay información para mostrarle.
            </p>
            <p class="ayuda">
                Comuníquese con el administrador del sistema para que le habilite el acceso.
                Una vez asignado, vuelva a iniciar sesión.
            </p>
            <button class="btn btn-secondary" @click="salir">Cerrar sesión</button>
        </div>
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { authService } from '@/services/auth';
import AtlasLogo from '@/components/AtlasLogo.vue';

const auth = useAuthStore();
const router = useRouter();

async function salir() {
    try { await authService.logout(); } catch { /* la sesión igual se cierra localmente */ }
    auth.clear();
    router.replace({ name: 'login' });
}
</script>

<style scoped>
.sin-acceso {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: var(--color-primary, #1a2e4a);
}
.caja {
    background: var(--color-surface, #fff);
    border-radius: var(--radius, 8px);
    box-shadow: var(--shadow-sm, 0 2px 8px rgba(0,0,0,0.15));
    padding: 36px 32px;
    max-width: 520px;
    text-align: center;
}
.caja h1 { font-size: 20px; margin: 16px 0 12px; color: var(--color-primary, #1a2e4a); }
.caja p { font-size: 14px; line-height: 1.6; color: var(--color-text, #333); margin: 0 0 12px; }
.caja .ayuda { color: var(--color-muted, #888); font-size: 13px; }
.caja .btn { margin-top: 12px; }
</style>
