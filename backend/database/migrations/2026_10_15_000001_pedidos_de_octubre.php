<?php

use App\Support\UnificadorExpedientes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos del 15/10:
 *
 *   - Fecha del comprobante en cada movimiento. Los que ya existen toman la
 *     fecha en que se cargaron, que es la única que se conoce.
 *   - Los contratos se pueden archivar: dejan de verse sin perder su historial.
 *     Las cuentas ya tenían su marca (`activo`).
 *   - El responsable de un nodo de la estructura se elige del personal. La
 *     columna de texto anterior queda en la base para no perder lo escrito.
 *   - Un solo expediente por número (ver UnificadorExpedientes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ejecucion_movimientos', 'fecha')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                ADD COLUMN fecha DATE NULL AFTER objeto,
                ADD KEY idx_em_fecha (fecha)');
        }
        DB::statement('UPDATE ejecucion_movimientos SET fecha = DATE(created_at)
                        WHERE fecha IS NULL AND created_at IS NOT NULL');

        if (!Schema::hasColumn('contratos', 'archivado')) {
            DB::statement('ALTER TABLE contratos
                ADD COLUMN archivado TINYINT(1) NOT NULL DEFAULT 0 AFTER observaciones');
        }

        if (!Schema::hasColumn('sector', 'responsable_id')) {
            DB::statement('ALTER TABLE sector
                ADD COLUMN responsable_id INT NULL AFTER dependencia_id,
                ADD CONSTRAINT fk_sector_responsable FOREIGN KEY (responsable_id)
                    REFERENCES personal(legajo) ON DELETE SET NULL ON UPDATE CASCADE');
        }

        UnificadorExpedientes::unificar();
    }

    public function down(): void
    {
        if (Schema::hasColumn('sector', 'responsable_id')) {
            DB::statement('ALTER TABLE sector DROP FOREIGN KEY fk_sector_responsable, DROP COLUMN responsable_id');
        }
        if (Schema::hasColumn('contratos', 'archivado')) {
            DB::statement('ALTER TABLE contratos DROP COLUMN archivado');
        }
        if (Schema::hasColumn('ejecucion_movimientos', 'fecha')) {
            DB::statement('ALTER TABLE ejecucion_movimientos DROP KEY idx_em_fecha, DROP COLUMN fecha');
        }
    }
};
