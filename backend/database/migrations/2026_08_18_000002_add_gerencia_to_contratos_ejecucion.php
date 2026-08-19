<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada contrato de ejecución pasa a estar vinculado a una gerencia (y por lo
 * tanto a una Gerencia de Área).
 *
 * Las dos columnas de texto libre que existían se resuelven así:
 *   - `gerencia`      -> se reemplaza por la FK `gerencia_id`; su contenido ya
 *                        quedó volcado al catálogo en la migración anterior.
 *   - `gerencia_area` -> en la práctica guardaba el departamento o laboratorio
 *                        del contrato, no una Gerencia de Área, así que su
 *                        contenido se conserva en `sector_detalle`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contratos_ejecucion', 'gerencia_id')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD COLUMN gerencia_id INT NULL AFTER contrato_principal_id');
        }
        if (!Schema::hasColumn('contratos_ejecucion', 'sector_detalle')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD COLUMN sector_detalle VARCHAR(200) NULL AFTER gerencia_id');
        }

        // El departamento / laboratorio se preserva antes de descartar la columna.
        if (Schema::hasColumn('contratos_ejecucion', 'gerencia_area')) {
            DB::statement("
                UPDATE contratos_ejecucion
                   SET sector_detalle = NULLIF(TRIM(gerencia_area), '')
                 WHERE sector_detalle IS NULL
            ");
        }

        $this->vincularContratos();

        DB::statement('ALTER TABLE contratos_ejecucion MODIFY COLUMN gerencia_id INT NOT NULL');

        if (!$this->indexExists('contratos_ejecucion', 'idx_ce_gerencia')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD KEY idx_ce_gerencia (gerencia_id)');
        }
        if (!$this->foreignKeyExists('contratos_ejecucion', 'fk_ce_gerencia')) {
            DB::statement('
                ALTER TABLE contratos_ejecucion
                ADD CONSTRAINT fk_ce_gerencia FOREIGN KEY (gerencia_id) REFERENCES gerencias(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        }

        foreach (['gerencia_area', 'gerencia'] as $col) {
            if (Schema::hasColumn('contratos_ejecucion', $col)) {
                DB::statement("ALTER TABLE contratos_ejecucion DROP COLUMN {$col}");
            }
        }
    }

    /** Resuelve la gerencia de cada contrato a partir del texto libre previo. */
    private function vincularContratos(): void
    {
        if (!DB::table('contratos_ejecucion')->whereNull('gerencia_id')->exists()) {
            // Instalación nueva o ya migrada: no hay nada que resolver.
            return;
        }

        $fallback = $this->gerenciaSinAsignar();

        if (!Schema::hasColumn('contratos_ejecucion', 'gerencia')) {
            DB::table('contratos_ejecucion')->whereNull('gerencia_id')
                ->update(['gerencia_id' => $fallback]);
            return;
        }

        // Una sola pasada por nombre de gerencia, en lugar de una por contrato.
        $porNombre = DB::table('gerencias')->pluck('id', 'nombre');

        foreach ($porNombre as $nombre => $gerenciaId) {
            DB::table('contratos_ejecucion')
                ->whereNull('gerencia_id')
                ->whereRaw('TRIM(gerencia) = ?', [$nombre])
                ->update(['gerencia_id' => $gerenciaId]);
        }

        // Lo que no matcheó (gerencia vacía o inesperada) va al destino de respaldo.
        DB::table('contratos_ejecucion')->whereNull('gerencia_id')
            ->update(['gerencia_id' => $fallback]);
    }

    private function gerenciaSinAsignar(): int
    {
        $areaId = DB::table('gerencias_area')->where('nombre', 'Sin asignar')->value('id')
            ?? DB::table('gerencias_area')->insertGetId([
                'nombre' => 'Sin asignar', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return DB::table('gerencias')
            ->where('gerencia_area_id', $areaId)->where('nombre', 'Sin asignar')->value('id')
            ?? DB::table('gerencias')->insertGetId([
                'gerencia_area_id' => $areaId, 'nombre' => 'Sin asignar',
                'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function indexExists(string $table, string $index): bool
    {
        return !empty(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]));
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint]
        ));
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('contratos_ejecucion', 'fk_ce_gerencia')) {
            DB::statement('ALTER TABLE contratos_ejecucion DROP FOREIGN KEY fk_ce_gerencia');
        }

        foreach (['gerencia_area', 'gerencia'] as $col) {
            if (!Schema::hasColumn('contratos_ejecucion', $col)) {
                DB::statement("ALTER TABLE contratos_ejecucion ADD COLUMN {$col} VARCHAR(200) NULL");
            }
        }

        // Se devuelven los textos a sus columnas originales antes de quitarlas.
        if (Schema::hasColumn('contratos_ejecucion', 'gerencia_id')) {
            DB::statement('
                UPDATE contratos_ejecucion ce
                  JOIN gerencias g ON g.id = ce.gerencia_id
                   SET ce.gerencia = g.nombre
            ');
            DB::statement('ALTER TABLE contratos_ejecucion DROP COLUMN gerencia_id');
        }
        if (Schema::hasColumn('contratos_ejecucion', 'sector_detalle')) {
            DB::statement('UPDATE contratos_ejecucion SET gerencia_area = sector_detalle');
            DB::statement('ALTER TABLE contratos_ejecucion DROP COLUMN sector_detalle');
        }
    }
};
