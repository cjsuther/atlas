<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El expediente pasa a imputarse a una cuenta operativa, y su rama se deduce
 * de ella: `sector_id` deja de cargarse a mano y queda como copia derivada del
 * nodo de la cuenta, que es lo que usan el alcance, el panel y las consultas.
 *
 * Se permite el nulo porque una cuenta puede colgar de la raíz del árbol —toda
 * la organización—, que no es ningún sector.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La cuenta es obligatoria: la migración anterior dejó a todos con una.
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY cuenta_operativa_id INT NOT NULL');
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY sector_id INT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE contratos_ejecucion SET sector_id = (
            SELECT co.sector_id FROM cuentas_operativas co WHERE co.id = contratos_ejecucion.cuenta_operativa_id
        ) WHERE sector_id IS NULL');
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY sector_id INT NOT NULL');
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY cuenta_operativa_id INT NULL');
    }
};
