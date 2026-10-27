<?php

use App\Support\UnificadorExpedientes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos del 27/10:
 *
 *   - Cada cuenta tiene un responsable, elegido del personal. Las que ya
 *     existen toman el del contrato o el del nodo del que cuelgan, si lo hay;
 *     las demás quedan sin responsable hasta que se las edite.
 *   - La repartición de los expedientes es GAAYG#CNEA. Los que se cargaron
 *     como GAAIG#CNEA se corrigen; si el número corregido ya existía, se
 *     unifican.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cuentas_operativas', 'responsable_id')) {
            DB::statement('ALTER TABLE cuentas_operativas
                ADD COLUMN responsable_id INT NULL AFTER descripcion,
                ADD CONSTRAINT fk_cuenta_responsable FOREIGN KEY (responsable_id)
                    REFERENCES personal(legajo) ON DELETE SET NULL ON UPDATE CASCADE');
        }

        DB::statement('UPDATE cuentas_operativas co
            LEFT JOIN contratos c ON c.sector_id = co.sector_id
            LEFT JOIN sector s    ON s.sector_id = co.sector_id
            SET co.responsable_id = COALESCE(c.resp1_id, s.responsable_id)
            WHERE co.responsable_id IS NULL
              AND COALESCE(c.resp1_id, s.responsable_id) IS NOT NULL');

        foreach (['expedientes', 'ejecucion_movimientos'] as $tabla) {
            DB::table($tabla)
                ->where('nro_expediente', 'like', '%GAAIG#CNEA')
                ->update(['nro_expediente' => DB::raw("REPLACE(nro_expediente, 'GAAIG#CNEA', 'GAAYG#CNEA')")]);
        }

        UnificadorExpedientes::unificar();
    }

    public function down(): void
    {
        if (Schema::hasColumn('cuentas_operativas', 'responsable_id')) {
            DB::statement('ALTER TABLE cuentas_operativas
                DROP FOREIGN KEY fk_cuenta_responsable, DROP COLUMN responsable_id');
        }
    }
};
