<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rol `sin_acceso`: el usuario puede autenticarse pero no ve nada del sistema.
 *
 * Es el perfil con el que se dan de alta los usuarios que llegan por LDAP: el
 * directorio valida quién es la persona, pero qué puede ver lo decide un
 * administrador asignándole después un rol y una Gerencia de Área.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Instalación nueva: el rol viejo ya no existe; el acceso sale de los permisos.
        if (!Schema::hasColumn('user_roles', 'rol')) {
            return;
        }

        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin_sistema','admin_gerencia','operador_gerencia','sin_acceso')
            NOT NULL DEFAULT 'sin_acceso'
        ");
    }

    public function down(): void
    {
        // Quien haya quedado sin acceso pasa a operador de gerencia, que es el
        // rol de menor alcance de los que quedan.
        DB::table('user_roles')->where('rol', 'sin_acceso')->update(['rol' => 'operador_gerencia']);

        DB::statement("
            ALTER TABLE user_roles MODIFY COLUMN rol
            ENUM('admin_sistema','admin_gerencia','operador_gerencia')
            NOT NULL DEFAULT 'operador_gerencia'
        ");
    }
};
