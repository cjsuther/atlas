<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El tercer nivel de la estructura se llama Caja, no Plan:
 *
 *   Gerencia de Área  ->  Gerencia  ->  Caja  ->  Contrato
 *
 * La preferencia de agrupación de saldos cambia de valor para quien ya la
 * tuviera fijada en ese nivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','plan','caja','contrato') NOT NULL DEFAULT 'gerencia_area'");

        DB::table('user_roles')->where('saldos_agrupacion', 'plan')
            ->update(['saldos_agrupacion' => 'caja']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','caja','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','plan','caja','contrato') NOT NULL DEFAULT 'gerencia_area'");

        DB::table('user_roles')->where('saldos_agrupacion', 'caja')
            ->update(['saldos_agrupacion' => 'plan']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','plan','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }
};
