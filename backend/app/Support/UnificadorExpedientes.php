<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Deja un solo expediente por número.
 *
 * El número de expediente identifica al expediente en todo el sistema: los
 * movimientos de cualquier cuenta apuntan al mismo registro. Antes cada
 * expediente pertenecía a una cuenta, y uno que tocaba varias —los saldos
 * iniciales, una distribución de fondos— se cargaba una vez por cuenta.
 *
 * De cada número repetido queda el registro más antiguo; los movimientos de
 * los demás pasan a él y los demás se borran. Los dados de baja no se tocan:
 * son historial.
 */
class UnificadorExpedientes
{
    /** @return int cantidad de registros repetidos que se eliminaron */
    public static function unificar(): int
    {
        $repetidos = DB::table('expedientes')
            ->whereNull('deleted_at')
            ->groupBy('nro_expediente')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('nro_expediente, MIN(id) AS queda')
            ->get();

        $eliminados = 0;
        foreach ($repetidos as $r) {
            $sobran = DB::table('expedientes')
                ->whereNull('deleted_at')
                ->where('nro_expediente', $r->nro_expediente)
                ->where('id', '<>', $r->queda)
                ->pluck('id')
                ->all();

            foreach (['expediente_id', 'expediente_contraparte_id'] as $columna) {
                DB::table('ejecucion_movimientos')
                    ->whereIn($columna, $sobran)
                    ->update([$columna => $r->queda]);
            }

            $eliminados += DB::table('expedientes')->whereIn('id', $sobran)->delete();
        }

        return $eliminados;
    }
}
