<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Se elimina la entidad UTT: dejó de usarse en el sistema.
 *
 * Primero se sueltan las referencias desde los contratos y recién después se
 * borra la tabla, porque las claves foráneas lo impedirían.
 */
return new class extends Migration
{
    /** Tablas que referenciaban a UTT, con el nombre de su clave foránea. */
    private const REFERENCIAS = [
        'contratos_ejecucion' => 'fk_ce_utt',
        'contratos_principal' => 'fk_cp_utt',
    ];

    public function up(): void
    {
        foreach (self::REFERENCIAS as $tabla => $fk) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }
            if ($this->foreignKeyExists($tabla, $fk)) {
                DB::statement("ALTER TABLE {$tabla} DROP FOREIGN KEY {$fk}");
            }
            if (Schema::hasColumn($tabla, 'utt_id')) {
                DB::statement("ALTER TABLE {$tabla} DROP COLUMN utt_id");
            }
        }

        Schema::dropIfExists('utt');
    }

    private function foreignKeyExists(string $tabla, string $constraint): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$tabla, $constraint]
        ));
    }

    /**
     * Recrea la tabla y las columnas, vacías: el contenido no se puede
     * recuperar desde acá, hay que restaurarlo de un backup.
     */
    public function down(): void
    {
        if (!Schema::hasTable('utt')) {
            DB::statement("
                CREATE TABLE utt (
                  utt_id       INT AUTO_INCREMENT PRIMARY KEY,
                  denominacion VARCHAR(50)  NOT NULL,
                  nombre       VARCHAR(300) NOT NULL,
                  regimen      VARCHAR(20)  NULL,
                  UNIQUE KEY uq_denom (denominacion)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        foreach (self::REFERENCIAS as $tabla => $fk) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }
            if (!Schema::hasColumn($tabla, 'utt_id')) {
                DB::statement("ALTER TABLE {$tabla} ADD COLUMN utt_id INT NULL");
            }
            if (!$this->foreignKeyExists($tabla, $fk)) {
                DB::statement("
                    ALTER TABLE {$tabla}
                    ADD CONSTRAINT {$fk} FOREIGN KEY (utt_id) REFERENCES utt(utt_id)
                    ON DELETE SET NULL ON UPDATE CASCADE
                ");
            }
        }
    }
};
