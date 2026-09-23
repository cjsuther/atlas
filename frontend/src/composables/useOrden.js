import { computed, reactive, unref } from 'vue';

/**
 * Ordena en el navegador las filas de una tabla.
 *
 * Es para las tablas que ya tienen todas sus filas cargadas. Las que se traen
 * por página —expedientes, catálogos— ordenan en el servidor, porque si no se
 * ordenaría sólo la página que se está viendo.
 *
 * `accesores` permite ordenar por algo que no es un campo directo de la fila:
 * el valor de una relación, una fecha sin formatear o un cálculo.
 *
 * @param {import('vue').Ref<Array>|Array} filas
 * @param {{ por?: string, dir?: 'asc'|'desc', accesores?: Record<string, Function> }} opciones
 */
export function useOrdenTabla(filas, { por = '', dir = 'asc', accesores = {} } = {}) {
    const orden = reactive({ by: por, dir });

    /** Un clic ordena ascendente; el siguiente sobre la misma columna invierte. */
    function ordenarPor(campo) {
        if (!campo) return;
        if (orden.by === campo) {
            orden.dir = orden.dir === 'asc' ? 'desc' : 'asc';
        } else {
            orden.by = campo;
            orden.dir = 'asc';
        }
    }

    const valor = (fila, campo) => (accesores[campo] ? accesores[campo](fila) : fila?.[campo]);

    const filasOrdenadas = computed(() => {
        const arr = [...(unref(filas) || [])];
        if (!orden.by) return arr;
        const signo = orden.dir === 'asc' ? 1 : -1;
        return arr.sort((a, b) => signo * comparar(valor(a, orden.by), valor(b, orden.by)));
    });

    return { orden, ordenarPor, filasOrdenadas };
}

/** Los vacíos van al final, los números comparan como números y el texto respeta acentos. */
function comparar(a, b) {
    const vacio = (v) => v === null || v === undefined || v === '';
    if (vacio(a) && vacio(b)) return 0;
    if (vacio(a)) return 1;
    if (vacio(b)) return -1;

    if (typeof a === 'boolean' || typeof b === 'boolean') return (a ? 1 : 0) - (b ? 1 : 0);

    const na = Number(a);
    const nb = Number(b);
    if (!Number.isNaN(na) && !Number.isNaN(nb) && a !== true && b !== true) return na - nb;

    return String(a).localeCompare(String(b), 'es', { numeric: true, sensitivity: 'base' });
}
