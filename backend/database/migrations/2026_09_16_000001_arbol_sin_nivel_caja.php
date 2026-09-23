<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El árbol vuelve a tener tres niveles:
 *
 *   Gerencia de Área  ->  Gerencia  ->  Contrato
 *
 * Las cuentas operativas siguen colgando de cualquiera de ellos, y es en las
 * cuentas donde se registran los movimientos. Quien viera los saldos agrupados
 * por Caja pasa a verlos por Contrato, que es ahora el último nivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','caja','contrato') NOT NULL DEFAULT 'gerencia_area'");

        DB::table('user_roles')->where('saldos_agrupacion', 'caja')
            ->update(['saldos_agrupacion' => 'contrato']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','caja','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }
};
