<?php

namespace App\Console\Commands;

use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class UserUpsertCommand extends Command
{
    protected $signature = 'atlas:user
                            {username        : Username (login) del usuario}
                            {--password=     : Contraseña en texto plano (se guarda hasheada). Si se omite, se pedirá interactivamente}
                            {--rol=consulta  : Rol: admin | operador | consulta}
                            {--display=      : Display name (opcional)}
                            {--email=        : Email (opcional)}
                            {--inactivo      : Marcar el usuario como inactivo}
                            {--clear-password : Quitar la contraseña local (deshabilita login local para este usuario)}';

    protected $description = 'Crea o actualiza un usuario con autenticación local (password hasheado en BD). Útil mientras no hay LDAP.';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        if ($username === '') {
            $this->error('Debe indicar un username.');
            return self::FAILURE;
        }

        $rol = (string) $this->option('rol');
        if (!in_array($rol, ['admin', 'operador', 'consulta'], true)) {
            $this->error("Rol inválido: {$rol}. Use admin | operador | consulta.");
            return self::FAILURE;
        }

        $user  = UserRole::firstOrNew(['username' => $username]);
        $isNew = !$user->exists;

        $user->rol    = $rol;
        $user->activo = $this->option('inactivo') ? 0 : 1;

        if ($display = $this->option('display')) {
            $user->display_name = $display;
        } elseif (!$user->display_name) {
            $user->display_name = $username;
        }

        if ($email = $this->option('email')) {
            $user->email = $email;
        }

        if ($this->option('clear-password')) {
            $user->password = null;
        } else {
            $password = $this->option('password');
            if ($password === null && $isNew) {
                $password = $this->secret('Contraseña para el usuario');
                $confirm  = $this->secret('Confirme la contraseña');
                if ($password !== $confirm) {
                    $this->error('Las contraseñas no coinciden.');
                    return self::FAILURE;
                }
            }
            if ($password !== null && $password !== '') {
                if (strlen($password) < 8) {
                    $this->error('La contraseña debe tener al menos 8 caracteres.');
                    return self::FAILURE;
                }
                $user->password = Hash::make($password);
            }
        }

        $user->save();

        $this->info(($isNew ? 'Creado' : 'Actualizado') . ": {$username} (rol={$user->rol}, activo=" . ($user->activo ? 'sí' : 'no') . ')');

        if (!$user->password) {
            $this->warn('El usuario no tiene contraseña local — sólo podrá ingresar vía LDAP.');
        }

        return self::SUCCESS;
    }
}
