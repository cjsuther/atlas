<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade la columna auth_source a user_roles para distinguir de forma explícita
 * los usuarios locales (con contraseña en BD) de los provenientes de LDAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_roles', 'auth_source')) {
            DB::statement(
                "ALTER TABLE user_roles
                 ADD COLUMN auth_source ENUM('local','ldap') NOT NULL DEFAULT 'ldap' AFTER password"
            );
        }

        // Backfill: quien ya tiene contraseña local se marca como 'local'.
        DB::statement("UPDATE user_roles SET auth_source = 'local' WHERE password IS NOT NULL AND password <> ''");
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_roles', 'auth_source')) {
            DB::statement('ALTER TABLE user_roles DROP COLUMN auth_source');
        }
    }
};
