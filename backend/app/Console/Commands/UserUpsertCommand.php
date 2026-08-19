<?php

namespace App\Console\Commands;

use App\Models\Sector;
use App\Models\UserRole;
use App\Support\SectorTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class UserUpsertCommand extends Command
{
    protected $signature = 'atlas:user
                            {username        : Username (login) del usuario}
                            {--password=     : Contraseña en texto plano (se guarda hasheada). Si se omite, se pedirá interactivamente}
                            {--rol=operador_gerencia : Rol: admin_sistema | admin_gerencia | operador_gerencia}
                            {--gerencia=     : ID o nombre de la Gerencia de Área (obligatorio salvo para admin_sistema)}
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
        if (!in_array($rol, UserRole::ROLES, true)) {
            $this->error("Rol inválido: {$rol}. Use " . implode(' | ', UserRole::ROLES) . '.');
            return self::FAILURE;
        }

        $user  = UserRole::firstOrNew(['username' => $username]);
        $isNew = !$user->exists;

        // Los roles acotados necesitan Gerencia de Área; el de sistema no.
        if (in_array($rol, UserRole::ROLES_CON_GERENCIA, true)) {
            $sectorId = $this->resolverGerenciaArea($user->sector_id);
            if ($sectorId === null) {
                return self::FAILURE;
            }
            $user->sector_id = $sectorId;
        } else {
            $user->sector_id = null;
        }

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

        // Origen: 'local' si tiene contraseña, 'ldap' si quedó sin contraseña local.
        $user->auth_source = $user->password ? 'local' : 'ldap';

        $user->save();

        $gerencia = $user->sector_id ? optional(Sector::find($user->sector_id))->nombre : '—';
        $this->info(($isNew ? 'Creado' : 'Actualizado')
            . ": {$username} (rol={$user->rol}, gerencia={$gerencia}, activo=" . ($user->activo ? 'sí' : 'no') . ')');

        if (!$user->password) {
            $this->warn('El usuario no tiene contraseña local — sólo podrá ingresar vía LDAP.');
        }

        return self::SUCCESS;
    }

    /**
     * Resuelve --gerencia por ID o por nombre. Debe ser una Gerencia de Área,
     * es decir un sector sin dependencia. Devuelve null si no se puede.
     */
    private function resolverGerenciaArea(?int $actual): ?int
    {
        $valor = $this->option('gerencia');

        if ($valor === null || $valor === '') {
            if ($actual) {
                return $actual;
            }
            $this->error('Debe indicar --gerencia para los roles acotados a una Gerencia de Área.');
            $this->listarGerenciasArea();
            return null;
        }

        $sector = is_numeric($valor)
            ? Sector::find((int) $valor)
            : Sector::where('nombre', $valor)->first();

        if (!$sector) {
            $this->error("No se encontró el sector: {$valor}");
            $this->listarGerenciasArea();
            return null;
        }

        if ($sector->dependencia_id !== null) {
            $this->error("'{$sector->nombre}' es un subsector; el usuario debe asociarse a una Gerencia de Área.");
            $this->listarGerenciasArea();
            return null;
        }

        return (int) $sector->sector_id;
    }

    private function listarGerenciasArea(): void
    {
        $raices = Sector::gerenciasArea()->orderBy('nombre')->get(['sector_id', 'nombre']);
        if ($raices->isEmpty()) {
            $this->warn('No hay Gerencias de Área cargadas en la tabla `sector`.');
            return;
        }
        $this->line('Gerencias de Área disponibles:');
        foreach ($raices as $r) {
            $this->line("  [{$r->sector_id}] {$r->nombre}");
        }
    }
}
