<?php

use App\Support\LimpiezaDatos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Vacía los datos del sistema para reimportarlos desde cero.
 *
 * Corre una sola vez, al arrancar el backend después de desplegar. Si hace
 * falta repetir la limpieza —por ejemplo, para reintentar una importación con
 * el archivo corregido— hay que usar `php artisan atlas:limpiar`, que hace
 * exactamente lo mismo y se puede ejecutar las veces que sea necesario.
 *
 * No se borran los usuarios ni sus tokens: sin ellos nadie podría entrar a
 * hacer la importación. Sí se les suelta la Gerencia de Área, porque los
 * sectores se van y sus ids no se conservan entre cargas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $borradas = LimpiezaDatos::ejecutar();

        Log::warning('ATLAS: se vaciaron los datos para reimportación', $borradas);
    }

    /**
     * No hay vuelta atrás: los datos borrados sólo se recuperan desde un backup
     * o volviendo a importar el archivo.
     */
    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
