<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada contrato de ejecución pasa a estar vinculado a una gerencia (y por lo
 * tanto a una Gerencia de Área). Las columnas de texto libre `gerencia_area`
 * y `gerencia` se reemplazan por la FK; su contenido ya quedó volcado al
 * catálogo en la migración anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contratos_ejecucion', 'gerencia_id')) {
            DB::statement('ALTER TABLE contratos_ejecucion ADD COLUMN gerencia_id INT NULL AFTER contrato_principal_id');
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
        $pendientes = DB::table('contratos_ejecucion')->whereNull('gerencia_id')->exists();
        if (!$pendientes) {
            // Instalación nueva o ya migrada: no hay nada que resolver.
            return;
        }

        if (!Schema::hasColumn('contratos_ejecucion', 'gerencia')) {
            DB::table('contratos_ejecucion')->whereNull('gerencia_id')
                ->update(['gerencia_id' => $this->gerenciaSinAsignar()]);
            return;
        }

        $fallback = $this->gerenciaSinAsignar();

        $contratos = DB::table('contratos_ejecucion')
            ->select('id', 'gerencia_area', 'gerencia')
            ->whereNull('gerencia_id')
            ->get();

        foreach ($contratos as $c) {
            $nombreArea     = trim((string) ($c->gerencia_area ?? '')) ?: 'Sin asignar';
            $nombreGerencia = trim((string) ($c->gerencia ?? ''))      ?: 'Sin asignar';

            $gerenciaId = DB::table('gerencias as g')
                ->join('gerencias_area as ga', 'ga.id', '=', 'g.gerencia_area_id')
                ->where('ga.nombre', $nombreArea)
                ->where('g.nombre', $nombreGerencia)
                ->value('g.id') ?? $fallback;

            DB::table('contratos_ejecucion')->where('id', $c->id)
                ->update(['gerencia_id' => $gerenciaId]);
        }
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
        foreach (['gerencia_area' => 'VARCHAR(200) NULL', 'gerencia' => 'VARCHAR(200) NULL'] as $col => $tipo) {
            if (!Schema::hasColumn('contratos_ejecucion', $col)) {
                DB::statement("ALTER TABLE contratos_ejecucion ADD COLUMN {$col} {$tipo}");
            }
        }
        if (Schema::hasColumn('contratos_ejecucion', 'gerencia_id')) {
            DB::statement('ALTER TABLE contratos_ejecucion DROP COLUMN gerencia_id');
        }
    }
};
