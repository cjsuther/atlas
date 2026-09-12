<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta operativa siempre cuelga de un nodo del árbol: no existe la cuenta
 * de "toda la organización". La raíz sigue estando en el árbol, pero sólo para
 * elegir alcance de permisos, no para imputar.
 *
 * Como consecuencia todo expediente tiene rama, así que `sector_id` vuelve a
 * ser obligatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cuentas_operativas')->whereNull('sector_id')->delete();

        DB::statement('ALTER TABLE cuentas_operativas MODIFY sector_id INT NOT NULL');
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY sector_id INT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas_operativas MODIFY sector_id INT NULL');
        DB::statement('ALTER TABLE contratos_ejecucion MODIFY sector_id INT NULL');
    }
};
