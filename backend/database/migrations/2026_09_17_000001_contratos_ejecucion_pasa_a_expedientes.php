<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la base llamaba `contratos_ejecucion` son los expedientes.
 *
 * «Contrato» pasó a ser el tercer nivel de la estructura —Gerencia de Área,
 * Gerencia, Contrato—, así que el registro que se imputa a una cuenta se llama
 * expediente en la base, en la API y en la pantalla.
 *
 * Las columnas que lo referencian acompañan el cambio:
 *   ejecucion_movimientos.contrato_ejecucion_id   -> expediente_id
 *   ejecucion_movimientos.contrato_contraparte_id -> expediente_contraparte_id
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contratos_ejecucion') && !Schema::hasTable('expedientes')) {
            Schema::rename('contratos_ejecucion', 'expedientes');
        }

        if (Schema::hasColumn('ejecucion_movimientos', 'contrato_ejecucion_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                RENAME COLUMN contrato_ejecucion_id TO expediente_id');
        }
        if (Schema::hasColumn('ejecucion_movimientos', 'contrato_contraparte_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                RENAME COLUMN contrato_contraparte_id TO expediente_contraparte_id');
        }

        // El historial guarda a qué tabla pertenece cada cambio.
        DB::table('historial_cambios')->where('tabla', 'contratos_ejecucion')
            ->update(['tabla' => 'expedientes']);
    }

    public function down(): void
    {
        if (Schema::hasTable('expedientes') && !Schema::hasTable('contratos_ejecucion')) {
            Schema::rename('expedientes', 'contratos_ejecucion');
        }

        if (Schema::hasColumn('ejecucion_movimientos', 'expediente_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                RENAME COLUMN expediente_id TO contrato_ejecucion_id');
        }
        if (Schema::hasColumn('ejecucion_movimientos', 'expediente_contraparte_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                RENAME COLUMN expediente_contraparte_id TO contrato_contraparte_id');
        }

        DB::table('historial_cambios')->where('tabla', 'expedientes')
            ->update(['tabla' => 'contratos_ejecucion']);
    }
};
