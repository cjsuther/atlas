<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La estructura suma un nivel entre la Gerencia y el Contrato: el Plan.
 *
 *   Gerencia de Área  ->  Gerencia  ->  Plan  ->  Contrato
 *
 * Los niveles salen de la profundidad del nodo, así que el árbol no cambia de
 * forma. Lo único que se guarda es la preferencia de agrupación de saldos, que
 * ahora admite abrir la tabla hasta los planes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','plan','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }

    public function down(): void
    {
        DB::table('user_roles')->where('saldos_agrupacion', 'plan')
            ->update(['saldos_agrupacion' => 'contrato']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }
};
