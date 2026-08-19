<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El contrato pasa a colgar de la estructura organizativa real, que es la
 * tabla `sector`:
 *
 *   Gerencia de Área (sector raíz)  ->  Subsector  ->  Contrato  ->  Movimiento
 *
 * Reemplaza a la gestión de contratos principales. Las columnas de texto libre
 * `gerencia_area` y `gerencia` desaparecen: en los datos ya guardaban el id del
 * sector raíz y el del subsector, así que `sector_id` toma el segundo y la
 * Gerencia de Área se deriva subiendo por `dependencia_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contratos_ejecucion', 'sector_id')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD COLUMN sector_id INT NULL AFTER contrato_principal_id');
        }

        $this->vincularSectores();

        DB::statement('ALTER TABLE contratos_ejecucion MODIFY COLUMN sector_id INT NOT NULL');

        if (!$this->indexExists('idx_ce_sector')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD KEY idx_ce_sector (sector_id)');
        }
        if (!$this->foreignKeyExists('fk_ce_sector')) {
            DB::statement('
                ALTER TABLE contratos_ejecucion
                ADD CONSTRAINT fk_ce_sector FOREIGN KEY (sector_id) REFERENCES sector(sector_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        }

        foreach (['gerencia_area', 'gerencia', 'sector_detalle'] as $col) {
            if (Schema::hasColumn('contratos_ejecucion', $col)) {
                DB::statement("ALTER TABLE contratos_ejecucion DROP COLUMN {$col}");
            }
        }
    }

    /**
     * Resuelve el sector de cada contrato a partir de lo que hubiera cargado.
     * Sólo se vinculan los valores que existen en `sector`; el resto queda para
     * que lo resuelva la importación o el administrador, y se informa por log.
     */
    private function vincularSectores(): void
    {
        if (!DB::table('contratos_ejecucion')->whereNull('sector_id')->exists()) {
            return;
        }

        $sectores = DB::table('sector')->pluck('sector_id')->all();

        // En los datos cargados `gerencia` guarda el id del subsector.
        if (Schema::hasColumn('contratos_ejecucion', 'gerencia') && $sectores) {
            DB::table('contratos_ejecucion')
                ->whereNull('sector_id')
                ->whereIn(DB::raw('CAST(NULLIF(TRIM(gerencia), \'\') AS UNSIGNED)'), $sectores)
                ->update(['sector_id' => DB::raw('CAST(TRIM(gerencia) AS UNSIGNED)')]);
        }

        // Si no hubo subsector, se intenta con la Gerencia de Área.
        if (Schema::hasColumn('contratos_ejecucion', 'gerencia_area') && $sectores) {
            DB::table('contratos_ejecucion')
                ->whereNull('sector_id')
                ->whereIn(DB::raw('CAST(NULLIF(TRIM(gerencia_area), \'\') AS UNSIGNED)'), $sectores)
                ->update(['sector_id' => DB::raw('CAST(TRIM(gerencia_area) AS UNSIGNED)')]);
        }

        $huerfanos = DB::table('contratos_ejecucion')->whereNull('sector_id')->count();
        if ($huerfanos > 0) {
            // No se inventa un sector: la estructura la define la organización.
            throw new RuntimeException(
                "Hay {$huerfanos} contrato(s) cuyo sector no existe en la tabla `sector`. "
                . 'Cargue primero la estructura de sectores (o importe la base con '
                . '`php artisan atlas:importar-legacy`) y vuelva a ejecutar la migración.'
            );
        }
    }

    private function indexExists(string $index): bool
    {
        return !empty(DB::select('SHOW INDEX FROM contratos_ejecucion WHERE Key_name = ?', [$index]));
    }

    private function foreignKeyExists(string $constraint): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['contratos_ejecucion', $constraint]
        ));
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('fk_ce_sector')) {
            DB::statement('ALTER TABLE contratos_ejecucion DROP FOREIGN KEY fk_ce_sector');
        }

        foreach (['gerencia_area', 'gerencia'] as $col) {
            if (!Schema::hasColumn('contratos_ejecucion', $col)) {
                DB::statement("ALTER TABLE contratos_ejecucion ADD COLUMN {$col} VARCHAR(200) NULL");
            }
        }

        if (Schema::hasColumn('contratos_ejecucion', 'sector_id')) {
            // Se devuelven los ids a las columnas de texto de las que salieron.
            DB::statement('
                UPDATE contratos_ejecucion ce
                  LEFT JOIN sector s ON s.sector_id = ce.sector_id
                   SET ce.gerencia      = ce.sector_id,
                       ce.gerencia_area = COALESCE(s.dependencia_id, ce.sector_id)
            ');
            DB::statement('ALTER TABLE contratos_ejecucion DROP COLUMN sector_id');
        }
    }
};
