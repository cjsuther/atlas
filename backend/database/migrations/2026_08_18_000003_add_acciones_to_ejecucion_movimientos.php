<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En la ejecución no sólo hay gastos e ingresos por facturas: también hay
 * transferencias hacia otro contrato (de la misma o de otra gerencia) y pagos
 * de incentivos o MCH (Mayor Carga Horaria).
 *
 * Por eso el movimiento incorpora `accion` y una contraparte flexible: no
 * siempre hay cliente o proveedor, a veces la contraparte es otro contrato y
 * a veces sólo un rubro.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $columnas = [
        'accion'                  => "ENUM('factura','transferencia','incentivo','mch') NOT NULL DEFAULT 'factura' AFTER tipo",
        'contraparte_tipo'        => "ENUM('cliente','proveedor','contrato','rubro') NULL AFTER nro_expediente",
        'contrato_contraparte_id' => 'INT NULL AFTER cliente',
        'rubro'                   => 'VARCHAR(200) NULL AFTER contrato_contraparte_id',
        'movimiento_espejo_id'    => 'INT NULL AFTER rubro',
    ];

    public function up(): void
    {
        foreach ($this->columnas as $col => $definicion) {
            if (!Schema::hasColumn('ejecucion_movimientos', $col)) {
                DB::statement("ALTER TABLE ejecucion_movimientos ADD COLUMN {$col} {$definicion}");
            }
        }

        // Los movimientos existentes son todos facturas: su contraparte es el
        // cliente (ingresos) o el proveedor (gastos) que ya tienen cargado.
        DB::statement("
            UPDATE ejecucion_movimientos
               SET contraparte_tipo = CASE WHEN tipo = 'ingreso' THEN 'cliente' ELSE 'proveedor' END
             WHERE contraparte_tipo IS NULL
        ");

        foreach ([
            'idx_em_accion'      => '(accion)',
            'idx_em_contraparte' => '(contrato_contraparte_id)',
            'idx_em_espejo'      => '(movimiento_espejo_id)',
        ] as $index => $cols) {
            if (empty(DB::select('SHOW INDEX FROM ejecucion_movimientos WHERE Key_name = ?', [$index]))) {
                DB::statement("ALTER TABLE ejecucion_movimientos ADD KEY {$index} {$cols}");
            }
        }

        $fkExiste = !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['ejecucion_movimientos', 'fk_em_contraparte']
        ));
        if (!$fkExiste) {
            DB::statement('
                ALTER TABLE ejecucion_movimientos
                ADD CONSTRAINT fk_em_contraparte FOREIGN KEY (contrato_contraparte_id)
                REFERENCES contratos_ejecucion(id) ON DELETE SET NULL ON UPDATE CASCADE
            ');
        }
    }

    public function down(): void
    {
        $fkExiste = !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['ejecucion_movimientos', 'fk_em_contraparte']
        ));
        if ($fkExiste) {
            DB::statement('ALTER TABLE ejecucion_movimientos DROP FOREIGN KEY fk_em_contraparte');
        }

        foreach (array_keys($this->columnas) as $col) {
            if (Schema::hasColumn('ejecucion_movimientos', $col)) {
                DB::statement("ALTER TABLE ejecucion_movimientos DROP COLUMN {$col}");
            }
        }
    }
};
