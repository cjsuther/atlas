<?php

namespace App\Console\Commands;

use App\Models\UserRole;
use Illuminate\Console\Command;

class EnsureAdminCommand extends Command
{
    protected $signature = 'atlas:ensure-admin {username : Username del AD para registrar como administrador de sistema}';

    protected $description = 'Asegura que el usuario indicado exista en user_roles con rol admin_sistema (idempotente).';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));

        if ($username === '') {
            $this->error('Debe indicar un username.');
            return self::FAILURE;
        }

        $user = UserRole::firstOrNew(['username' => $username]);
        $user->rol = UserRole::ROL_ADMIN_SISTEMA;
        $user->sector_id = null; // el administrador de sistema no está acotado a una Gerencia de Área
        $user->activo = 1;
        if (!$user->display_name) {
            $user->display_name = $username;
        }
        $user->save();

        $this->info("Administrador de sistema asegurado: {$username}");
        return self::SUCCESS;
    }
}
