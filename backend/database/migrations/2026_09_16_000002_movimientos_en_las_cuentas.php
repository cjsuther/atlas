<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los movimientos pasan a registrarse en la cuenta, no en el expediente.
 *
 * La plata vive en la cuenta: cada una tiene su saldo inicial y su historial de
 * ingresos, gastos, transferencias, incentivos y MCH. El contrato queda como un
 * dato opcional del movimiento —«contrato relacionado»—, porque una cuenta puede
 * acumular ingresos de varios convenios y sus gastos no siempre se vinculan a
 * uno.
 *
 * Migración de lo ya cargado:
 *   - cada movimiento toma la cuenta del expediente al que estaba imputado;
 *   - el saldo inicial de cada cuenta pasa a ser la suma de los saldos
 *     iniciales de sus expedientes, que quedan en cero para no contarse dos
 *     veces;
 *   - las transferencias, que iban de expediente a expediente, pasan a ir de
 *     cuenta a cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Instalación nueva: los movimientos ya nacen en la cuenta.
        if (!Schema::hasTable('contratos_ejecucion')) {
            return;
        }

        if (!Schema::hasColumn('cuentas_operativas', 'saldo_inicial')) {
            DB::statement('ALTER TABLE cuentas_operativas
                ADD COLUMN saldo_inicial DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER descripcion');
        }

        if (!Schema::hasColumn('ejecucion_movimientos', 'cuenta_operativa_id')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                ADD COLUMN cuenta_operativa_id INT NULL AFTER contrato_ejecucion_id,
                ADD COLUMN cuenta_contraparte_id INT NULL AFTER contrato_contraparte_id');
        }

        // 1) Cada movimiento a la cuenta de su expediente.
        DB::statement('UPDATE ejecucion_movimientos m
            JOIN contratos_ejecucion c ON c.id = m.contrato_ejecucion_id
               SET m.cuenta_operativa_id = c.cuenta_operativa_id
             WHERE m.cuenta_operativa_id IS NULL');

        // 2) Las transferencias, entre las cuentas de esos expedientes.
        DB::statement('UPDATE ejecucion_movimientos m
            JOIN contratos_ejecucion c ON c.id = m.contrato_contraparte_id
               SET m.cuenta_contraparte_id = c.cuenta_operativa_id
             WHERE m.contrato_contraparte_id IS NOT NULL
               AND m.cuenta_contraparte_id IS NULL');

        // 3) El saldo inicial deja el expediente y pasa a la cuenta.
        DB::statement('UPDATE cuentas_operativas co
               SET co.saldo_inicial = COALESCE((
                     SELECT SUM(c.saldo_inicial) FROM contratos_ejecucion c
                      WHERE c.cuenta_operativa_id = co.id AND c.deleted_at IS NULL
                   ), 0)');
        DB::statement('UPDATE contratos_ejecucion SET saldo_inicial = 0');

        // 4) La cuenta es obligatoria y el contrato pasa a ser opcional.
        $sinCuenta = DB::table('ejecucion_movimientos')->whereNull('cuenta_operativa_id')->count();
        if ($sinCuenta > 0) {
            throw new RuntimeException(
                "Hay {$sinCuenta} movimiento(s) sin cuenta: revise que sus expedientes tengan cuenta operativa."
            );
        }

        DB::statement('ALTER TABLE ejecucion_movimientos MODIFY cuenta_operativa_id INT NOT NULL');
        DB::statement('ALTER TABLE ejecucion_movimientos MODIFY contrato_ejecucion_id INT NULL');

        // El expediente ya no manda: al darlo de baja el movimiento queda en la
        // cuenta, sin contrato relacionado.
        if ($this->tieneForanea('fk_em_contrato')) {
            DB::statement('ALTER TABLE ejecucion_movimientos DROP FOREIGN KEY fk_em_contrato');
        }
        DB::statement('ALTER TABLE ejecucion_movimientos
            ADD CONSTRAINT fk_em_contrato FOREIGN KEY (contrato_ejecucion_id)
                REFERENCES contratos_ejecucion(id) ON DELETE SET NULL ON UPDATE CASCADE');

        if (!$this->tieneForanea('fk_em_cuenta')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                ADD KEY idx_em_cuenta (cuenta_operativa_id),
                ADD KEY idx_em_cuenta_contraparte (cuenta_contraparte_id),
                ADD CONSTRAINT fk_em_cuenta FOREIGN KEY (cuenta_operativa_id)
                    REFERENCES cuentas_operativas(id) ON UPDATE CASCADE,
                ADD CONSTRAINT fk_em_cuenta_contraparte FOREIGN KEY (cuenta_contraparte_id)
                    REFERENCES cuentas_operativas(id) ON DELETE SET NULL ON UPDATE CASCADE');
        }

        // 5) La contraparte de una transferencia es una cuenta.
        DB::statement("ALTER TABLE ejecucion_movimientos MODIFY contraparte_tipo
            ENUM('cliente','proveedor','contrato','cuenta','rubro') NULL");
        DB::table('ejecucion_movimientos')->where('contraparte_tipo', 'contrato')
            ->update(['contraparte_tipo' => 'cuenta']);
        DB::statement("ALTER TABLE ejecucion_movimientos MODIFY contraparte_tipo
            ENUM('cliente','proveedor','cuenta','rubro') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ejecucion_movimientos MODIFY contraparte_tipo
            ENUM('cliente','proveedor','contrato','cuenta','rubro') NULL");
        DB::table('ejecucion_movimientos')->where('contraparte_tipo', 'cuenta')
            ->update(['contraparte_tipo' => 'contrato']);
        DB::statement("ALTER TABLE ejecucion_movimientos MODIFY contraparte_tipo
            ENUM('cliente','proveedor','contrato','rubro') NULL");

        // El saldo inicial vuelve al expediente: el de cada cuenta se deja en
        // el primero de los suyos, que es hasta donde se puede reconstruir.
        foreach (DB::table('cuentas_operativas')->where('saldo_inicial', '<>', 0)->get() as $cuenta) {
            $expediente = DB::table('contratos_ejecucion')
                ->where('cuenta_operativa_id', $cuenta->id)->whereNull('deleted_at')
                ->orderBy('id')->value('id');
            if ($expediente !== null) {
                DB::table('contratos_ejecucion')->where('id', $expediente)
                    ->update(['saldo_inicial' => $cuenta->saldo_inicial]);
            }
        }

        DB::table('ejecucion_movimientos')->whereNull('contrato_ejecucion_id')->delete();

        if ($this->tieneForanea('fk_em_cuenta')) {
            DB::statement('ALTER TABLE ejecucion_movimientos
                DROP FOREIGN KEY fk_em_cuenta, DROP FOREIGN KEY fk_em_cuenta_contraparte');
            DB::statement('ALTER TABLE ejecucion_movimientos
                DROP KEY idx_em_cuenta, DROP KEY idx_em_cuenta_contraparte');
        }
        DB::statement('ALTER TABLE ejecucion_movimientos
            DROP COLUMN cuenta_operativa_id, DROP COLUMN cuenta_contraparte_id');
        DB::statement('ALTER TABLE ejecucion_movimientos MODIFY contrato_ejecucion_id INT NOT NULL');

        if ($this->tieneForanea('fk_em_contrato')) {
            DB::statement('ALTER TABLE ejecucion_movimientos DROP FOREIGN KEY fk_em_contrato');
        }
        DB::statement('ALTER TABLE ejecucion_movimientos
            ADD CONSTRAINT fk_em_contrato FOREIGN KEY (contrato_ejecucion_id)
                REFERENCES contratos_ejecucion(id) ON DELETE CASCADE ON UPDATE CASCADE');

        DB::statement('ALTER TABLE cuentas_operativas DROP COLUMN saldo_inicial');
    }

    private function tieneForanea(string $nombre): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            ['ejecucion_movimientos', $nombre]
        ));
    }
};
