<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El expediente queda con lo mínimo: su número y la cuenta a la que va.
 *
 * Todo lo demás —tipo, estado, proyecto, fechas, solicitante, UVT,
 * responsables, moneda— deja de usarse y se elimina. La rama (`sector_id`) se
 * conserva porque se deduce de la cuenta y es por donde filtran el alcance y el
 * panel.
 */
return new class extends Migration
{
    /** Claves foráneas de las columnas que se van. */
    private const FORANEAS = [
        'fk_ce_estado', 'fk_ce_principal', 'fk_ce_resp1', 'fk_ce_resp2',
        'fk_ce_solic', 'fk_ce_tipo', 'fk_ce_uvt',
    ];

    private const INDICES = [
        'idx_ce_nombre', 'idx_ce_estado', 'idx_ce_tipo', 'idx_ce_principal',
        'idx_ce_uvt', 'idx_ce_solicitante', 'idx_ce_vencimiento',
    ];

    private const COLUMNAS = [
        'fecha_apertura_expediente', 'tipo_contrato_id', 'nombre_proyecto',
        'descripcion_objeto', 'contrato_principal_id', 'solicitante_id',
        'resp1_id', 'resp2_id', 'estado_id', 'observaciones', 'uvt_id',
        'cliente', 'fecha_inicio', 'fecha_vencimiento', 'fecha_finalizacion',
        'acta_finalizacion', 'prorroga', 'renovacion_automatica', 'caja_bas',
        'moneda', 'cotizacion', 'saldo_inicial',
    ];

    public function up(): void
    {
        foreach (self::FORANEAS as $fk) {
            if ($this->existe('TABLE_CONSTRAINTS', 'CONSTRAINT_NAME', $fk)) {
                DB::statement("ALTER TABLE expedientes DROP FOREIGN KEY {$fk}");
            }
        }

        foreach (self::INDICES as $idx) {
            if ($this->existe('STATISTICS', 'INDEX_NAME', $idx)) {
                DB::statement("ALTER TABLE expedientes DROP INDEX {$idx}");
            }
        }

        $aBorrar = array_values(array_filter(
            self::COLUMNAS,
            fn ($c) => Schema::hasColumn('expedientes', $c)
        ));

        if ($aBorrar) {
            $lista = implode(', ', array_map(fn ($c) => "DROP COLUMN {$c}", $aBorrar));
            DB::statement("ALTER TABLE expedientes {$lista}");
        }
    }

    /**
     * Vuelven las columnas, vacías: lo que tenían no se puede reconstruir. Las
     * que no admitían nulo quedan con un valor neutro para no romper el alta.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE expedientes
            ADD COLUMN fecha_apertura_expediente DATE NULL,
            ADD COLUMN tipo_contrato_id INT NOT NULL DEFAULT 0,
            ADD COLUMN nombre_proyecto VARCHAR(500) NOT NULL DEFAULT '',
            ADD COLUMN descripcion_objeto TEXT NULL,
            ADD COLUMN contrato_principal_id INT NULL,
            ADD COLUMN solicitante_id INT NULL,
            ADD COLUMN resp1_id INT NULL,
            ADD COLUMN resp2_id INT NULL,
            ADD COLUMN estado_id INT NOT NULL DEFAULT 0,
            ADD COLUMN observaciones TEXT NULL,
            ADD COLUMN uvt_id INT NULL,
            ADD COLUMN cliente VARCHAR(300) NULL,
            ADD COLUMN fecha_inicio DATE NULL,
            ADD COLUMN fecha_vencimiento DATE NULL,
            ADD COLUMN fecha_finalizacion DATE NULL,
            ADD COLUMN acta_finalizacion VARCHAR(500) NULL,
            ADD COLUMN prorroga TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN renovacion_automatica TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN caja_bas VARCHAR(200) NULL,
            ADD COLUMN moneda ENUM('Peso','Dólar','Euro','Otro') NOT NULL DEFAULT 'Peso',
            ADD COLUMN cotizacion DECIMAL(18,4) NULL,
            ADD COLUMN saldo_inicial DECIMAL(18,2) NULL");
    }

    private function existe(string $tabla, string $columna, string $valor): bool
    {
        return !empty(DB::select(
            "SELECT 1 FROM information_schema.{$tabla}
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expedientes' AND {$columna} = ?",
            [$valor]
        ));
    }
};
