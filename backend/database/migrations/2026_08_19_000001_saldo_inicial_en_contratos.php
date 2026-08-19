<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El monto presupuestado de ingresos era, en los datos cargados, el saldo con
 * el que arranca el contrato, así que pasa a llamarse `saldo_inicial`.
 *
 * El presupuesto de gastos se elimina: no se usa. El saldo de un contrato es
 * ahora `saldo_inicial + ingresos ejecutados - gastos ejecutados`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contratos_ejecucion', 'monto_presupuestado_ingresos')
            && !Schema::hasColumn('contratos_ejecucion', 'saldo_inicial')) {
            DB::statement('ALTER TABLE contratos_ejecucion
                           CHANGE COLUMN monto_presupuestado_ingresos saldo_inicial DECIMAL(18,2) NULL');
        }

        if (Schema::hasColumn('contratos_ejecucion', 'monto_presupuestado_gastos')) {
            DB::statement('ALTER TABLE contratos_ejecucion DROP COLUMN monto_presupuestado_gastos');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contratos_ejecucion', 'saldo_inicial')) {
            DB::statement('ALTER TABLE contratos_ejecucion
                           CHANGE COLUMN saldo_inicial monto_presupuestado_ingresos DECIMAL(18,2) NULL');
        }
        if (!Schema::hasColumn('contratos_ejecucion', 'monto_presupuestado_gastos')) {
            DB::statement('ALTER TABLE contratos_ejecucion
                           ADD COLUMN monto_presupuestado_gastos DECIMAL(18,2) NULL AFTER monto_presupuestado_ingresos');
        }
    }
};
