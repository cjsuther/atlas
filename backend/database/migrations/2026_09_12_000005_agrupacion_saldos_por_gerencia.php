<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La preferencia de agrupación de saldos sigue el vocabulario nuevo: lo que se
 * llamaba «subsector» es una Gerencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','subsector','gerencia','contrato') NOT NULL DEFAULT 'gerencia_area'");

        DB::table('user_roles')->where('saldos_agrupacion', 'subsector')
            ->update(['saldos_agrupacion' => 'gerencia']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','gerencia','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','subsector','gerencia','contrato') NOT NULL DEFAULT 'gerencia_area'");

        DB::table('user_roles')->where('saldos_agrupacion', 'gerencia')
            ->update(['saldos_agrupacion' => 'subsector']);

        DB::statement("ALTER TABLE user_roles MODIFY saldos_agrupacion
            ENUM('gerencia_area','subsector','contrato') NOT NULL DEFAULT 'gerencia_area'");
    }
};
