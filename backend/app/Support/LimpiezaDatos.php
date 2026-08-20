<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Vacía los datos del sistema para volver a cargarlos desde cero.
 *
 * Se borra exactamente lo que la importación vuelve a traer. Quedan afuera los
 * usuarios y sus tokens: si se borraran, nadie podría entrar a hacer la
 * importación. Como los sectores sí se borran, se les suelta la Gerencia de
 * Área asignada para no dejar la referencia colgando; hay que volver a
 * asignarla después de importar.
 */
class LimpiezaDatos
{
    /**
     * Tablas a vaciar, de la hija a la madre. El orden no es estrictamente
     * necesario porque se desactivan las claves foráneas, pero deja claro qué
     * depende de qué.
     */
    public const TABLAS = [
        'historial_cambios',
        'ejecucion_movimientos',
        'contratos_ejecucion',
        'contratos_principal',
        'personal',
        'sector',
        'solicitantes',
        'uvt',
        'estado_ejecucion',
        'estado_principal',
        'tipo_contrato_ejecucion',
        'tipo_contrato_principal',
    ];

    /**
     * @return array<string, int> tabla => filas que tenía antes de vaciarse
     */
    public static function ejecutar(bool $borrarFacturas = true): array
    {
        $borradas = [];

        // Sin transacción a propósito: TRUNCATE hace commit implícito en MySQL,
        // así que envolverlo no aportaría atomicidad. La operación es
        // destructiva por definición; lo que la respalda es el backup previo.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            // Los usuarios sobreviven, pero su Gerencia de Área no: los
            // sectores se van y los ids no se conservan entre cargas.
            if (Schema::hasColumn('user_roles', 'sector_id')) {
                $borradas['user_roles.sector_id (en blanco)'] =
                    DB::table('user_roles')->whereNotNull('sector_id')->update(['sector_id' => null]);
            }

            foreach (self::TABLAS as $tabla) {
                if (!Schema::hasTable($tabla)) {
                    continue;
                }
                $borradas[$tabla] = DB::table($tabla)->count();
                // TRUNCATE además reinicia los autoincrementales, así los
                // ids vuelven a arrancar de uno.
                DB::statement("TRUNCATE TABLE {$tabla}");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        if ($borrarFacturas) {
            $borradas['facturas (archivos)'] = self::borrarFacturas();
        }

        return $borradas;
    }

    /**
     * Las facturas adjuntas quedarían huérfanas: sus movimientos ya no existen.
     */
    private static function borrarFacturas(): int
    {
        $disk = Storage::disk('local');
        if (!$disk->exists('facturas')) {
            return 0;
        }
        $archivos = $disk->files('facturas');
        $disk->deleteDirectory('facturas');
        return count($archivos);
    }
}
