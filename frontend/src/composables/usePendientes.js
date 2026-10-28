import { ref } from 'vue';
import { aprobacionesService } from '@/services/aprobaciones';

/**
 * Cuánto espera la decisión del aprobador. Es uno solo para toda la
 * aplicación: el menú lo muestra y la bandeja lo actualiza al decidir.
 */
const cantidad = ref(0);

async function refrescar() {
    try {
        cantidad.value = (await aprobacionesService.cantidad()).cantidad || 0;
    } catch {
        // Sin conexión o sin sesión: el contador queda como estaba.
    }
}

export function usePendientes() {
    return { cantidad, refrescar };
}
