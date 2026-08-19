<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nuevos roles con alcance por Gerencia de Área:
 *
 *   admin (sistema)   -> admin_sistema     : todas las Gerencias de Área
 *   operador          -> operador_gerencia : su Gerencia de Área
 *   consulta          -> operador_gerencia : su Gerencia de Área
 *
 * `sector_id` apunta al sector raíz (la Gerencia de Área) al que está asociado
 * el usuario, y es obligatorio para los roles acotados. `saldos_agrupacion` es
 * la configuración con la que ve los saldos del panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El ENUM debe admitir los valores viejos y los nuevos a la vez para
        // poder reasignar los roles sin perder filas.
        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin','operador','consulta','admin_sistema','admin_gerencia','operador_gerencia')
            NOT NULL DEFAULT 'operador_gerencia'
        ");

        DB::table('user_roles')->where('rol', 'admin')->update(['rol' => 'admin_sistema']);
        DB::table('user_roles')->whereIn('rol', ['operador', 'consulta'])->update(['rol' => 'operador_gerencia']);

        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin_sistema','admin_gerencia','operador_gerencia')
            NOT NULL DEFAULT 'operador_gerencia'
        ");

        if (!Schema::hasColumn('user_roles', 'sector_id')) {
            DB::statement('ALTER TABLE user_roles ADD COLUMN sector_id INT NULL AFTER rol');
        }
        if (!Schema::hasColumn('user_roles', 'saldos_agrupacion')) {
            DB::statement("
                ALTER TABLE user_roles ADD COLUMN saldos_agrupacion
                ENUM('gerencia_area','subsector','contrato') NOT NULL DEFAULT 'gerencia_area' AFTER sector_id
            ");
        }

        if (empty(DB::select('SHOW INDEX FROM user_roles WHERE Key_name = ?', ['idx_ur_sector']))) {
            DB::statement('ALTER TABLE user_roles ADD KEY idx_ur_sector (sector_id)');
        }
        if (!$this->foreignKeyExists('fk_ur_sector')) {
            DB::statement('
                ALTER TABLE user_roles
                ADD CONSTRAINT fk_ur_sector FOREIGN KEY (sector_id) REFERENCES sector(sector_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        }

        // No se asigna una Gerencia de Área por defecto: el alcance de cada
        // usuario lo define la organización. Los que quedan sin sector no ven
        // contratos hasta que un administrador se los asigne.
    }

    private function foreignKeyExists(string $constraint): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['user_roles', $constraint]
        ));
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('fk_ur_sector')) {
            DB::statement('ALTER TABLE user_roles DROP FOREIGN KEY fk_ur_sector');
        }
        foreach (['sector_id', 'saldos_agrupacion'] as $col) {
            if (Schema::hasColumn('user_roles', $col)) {
                DB::statement("ALTER TABLE user_roles DROP COLUMN {$col}");
            }
        }

        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin','operador','consulta','admin_sistema','admin_gerencia','operador_gerencia')
            NOT NULL DEFAULT 'consulta'
        ");
        DB::table('user_roles')->where('rol', 'admin_sistema')->update(['rol' => 'admin']);
        DB::table('user_roles')->whereIn('rol', ['admin_gerencia', 'operador_gerencia'])->update(['rol' => 'operador']);
        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta'
        ");
    }
};
