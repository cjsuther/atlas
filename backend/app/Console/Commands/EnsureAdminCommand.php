<?php

namespace App\Console\Commands;

use App\Models\UserRole;
use Illuminate\Console\Command;

class EnsureAdminCommand extends Command
{
    protected $signature = 'atlas:ensure-admin {username : Username del AD para registrar como administrador del sistema}';

    protected $description = 'Asegura que el usuario indicado exista en user_roles como administrador del sistema (idempotente).';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));

        if ($username === '') {
            $this->error('Debe indicar un username.');
            return self::FAILURE;
        }

        $user = UserRole::firstOrNew(['username' => $username]);
        $user->es_admin = true;  // el administrador ve y opera sobre todo el árbol
        $user->activo   = 1;
        if (!$user->display_name) {
            $user->display_name = $username;
        }
        $user->save();

        $this->info("Administrador de sistema asegurado: {$username}");
        return self::SUCCESS;
    }
}
