<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nuevos roles con alcance por gerencia:
 *
 *   admin (sistema)   -> admin_sistema     : todas las gerencias de área
 *   operador          -> operador_gerencia : su gerencia
 *   consulta          -> operador_gerencia : su gerencia
 *
 * Se agrega además `gerencia_id` (obligatorio para los roles acotados) y
 * `saldos_agrupacion`, la configuración con la que el usuario ve los saldos
 * del panel: por Gerencia de Área, por Gerencia o por Contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) El ENUM debe admitir los valores viejos y los nuevos a la vez para
        //    poder reasignar los roles sin perder filas.
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

        // 2) Alcance y preferencia de visualización de saldos.
        if (!Schema::hasColumn('user_roles', 'gerencia_id')) {
            DB::statement('ALTER TABLE user_roles ADD COLUMN gerencia_id INT NULL AFTER rol');
        }
        if (!Schema::hasColumn('user_roles', 'saldos_agrupacion')) {
            DB::statement("
                ALTER TABLE user_roles ADD COLUMN saldos_agrupacion
                ENUM('gerencia_area','gerencia','contrato') NOT NULL DEFAULT 'gerencia' AFTER gerencia_id
            ");
        }

        if (empty(DB::select('SHOW INDEX FROM user_roles WHERE Key_name = ?', ['idx_ur_gerencia']))) {
            DB::statement('ALTER TABLE user_roles ADD KEY idx_ur_gerencia (gerencia_id)');
        }
        if (!$this->foreignKeyExists('fk_ur_gerencia')) {
            DB::statement('
                ALTER TABLE user_roles
                ADD CONSTRAINT fk_ur_gerencia FOREIGN KEY (gerencia_id) REFERENCES gerencias(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        }

        // 3) Los usuarios que quedaron acotados a una gerencia necesitan una:
        //    se les asigna la de respaldo hasta que el administrador los reubique.
        $sinGerencia = DB::table('user_roles')
            ->where('rol', '!=', 'admin_sistema')
            ->whereNull('gerencia_id')
            ->exists();
        if ($sinGerencia) {
            DB::table('user_roles')
                ->where('rol', '!=', 'admin_sistema')
                ->whereNull('gerencia_id')
                ->update(['gerencia_id' => $this->gerenciaSinAsignar()]);
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
        if ($this->foreignKeyExists('fk_ur_gerencia')) {
            DB::statement('ALTER TABLE user_roles DROP FOREIGN KEY fk_ur_gerencia');
        }
        foreach (['gerencia_id', 'saldos_agrupacion'] as $col) {
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
