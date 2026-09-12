<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los permisos dejan de ser un rol fijo y pasan a ser asignaciones sobre el
 * árbol de la estructura.
 *
 * A un usuario se le asigna un nodo —o la raíz, que es toda la organización—
 * con nivel de lectura o de escritura. El permiso se hereda hacia abajo: quien
 * puede escribir en una Gerencia de Área puede hacerlo en sus gerencias, en sus
 * contratos y en las cuentas de toda esa rama. Quien puede escribir, puede ver.
 *
 * Aparte del árbol queda un único atributo de sistema, `es_admin`, que habilita
 * la configuración: estructura, cuentas, catálogos, usuarios y respaldos.
 *
 * Equivalencias al migrar:
 *   admin_sistema      -> es_admin, con escritura sobre toda la organización
 *   admin_gerencia     -> escritura sobre su Gerencia de Área
 *   operador_gerencia  -> escritura sobre su Gerencia de Área
 *   sin_acceso         -> sin asignaciones
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_permisos', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_role_id');
            $table->integer('sector_id')->nullable()
                  ->comment('Nodo del árbol. Null = toda la organización (la raíz).');
            $table->enum('nivel', ['lectura', 'escritura'])->default('lectura');
            $table->timestamps();

            $table->unique(['user_role_id', 'sector_id']);
            $table->foreign('user_role_id')->references('id')->on('user_roles')->onDelete('cascade');
            $table->foreign('sector_id')->references('sector_id')->on('sector')->onDelete('cascade');
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->boolean('es_admin')->default(false)->after('auth_source');
        });

        $ahora = now();
        $filas = [];

        foreach (DB::table('user_roles')->get() as $u) {
            if ($u->rol === 'admin_sistema') {
                DB::table('user_roles')->where('id', $u->id)->update(['es_admin' => 1]);
                $filas[] = [
                    'user_role_id' => $u->id,
                    'sector_id'    => null,
                    'nivel'        => 'escritura',
                    'created_at'   => $ahora,
                    'updated_at'   => $ahora,
                ];
                continue;
            }

            // Los roles acotados se traducen a escritura sobre su Gerencia de Área.
            if (in_array($u->rol, ['admin_gerencia', 'operador_gerencia'], true) && $u->sector_id) {
                $filas[] = [
                    'user_role_id' => $u->id,
                    'sector_id'    => $u->sector_id,
                    'nivel'        => 'escritura',
                    'created_at'   => $ahora,
                    'updated_at'   => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 100) as $lote) {
            DB::table('usuario_permisos')->insert($lote);
        }

        // La clave foránea del esquema de instalación no sigue la convención de
        // nombres de Laravel, así que se busca cómo se llama antes de soltarla.
        $fk = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'user_roles')
            ->where('COLUMN_NAME', 'sector_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        if ($fk) {
            DB::statement("ALTER TABLE user_roles DROP FOREIGN KEY `{$fk}`");
        }

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn(['rol', 'sector_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->enum('rol', ['admin_sistema', 'admin_gerencia', 'operador_gerencia', 'sin_acceso'])
                  ->default('sin_acceso')->after('es_admin');
            $table->integer('sector_id')->nullable()->after('rol');
            $table->foreign('sector_id')->references('sector_id')->on('sector');
        });

        DB::table('user_roles')->where('es_admin', 1)->update(['rol' => 'admin_sistema']);

        foreach (DB::table('usuario_permisos')->whereNotNull('sector_id')->get() as $p) {
            DB::table('user_roles')->where('id', $p->user_role_id)->where('es_admin', 0)
              ->update(['rol' => 'operador_gerencia', 'sector_id' => $p->sector_id]);
        }

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn('es_admin');
        });

        Schema::dropIfExists('usuario_permisos');
    }
};
