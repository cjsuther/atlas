<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un ingreso por factura se puede repartir entre otras cuentas por
 * porcentajes. Cada parte es una transferencia que sale de la cuenta del
 * ingreso; ella y su contrapartida apuntan al ingreso que las generó y
 * guardan el porcentaje, para recalcularlas si el ingreso cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ejecucion_movimientos', 'distribucion_de_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                ADD COLUMN distribucion_de_id INT NULL AFTER movimiento_espejo_id,
                ADD COLUMN distribucion_porcentaje DECIMAL(5,2) NULL AFTER distribucion_de_id,
                ADD KEY idx_em_distribucion (distribucion_de_id),
                ADD CONSTRAINT fk_em_distribucion FOREIGN KEY (distribucion_de_id)
                    REFERENCES ejecucion_movimientos(id) ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ejecucion_movimientos', 'distribucion_de_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                DROP FOREIGN KEY fk_em_distribucion,
                DROP KEY idx_em_distribucion,
                DROP COLUMN distribucion_porcentaje,
                DROP COLUMN distribucion_de_id');
        }
    }
};
