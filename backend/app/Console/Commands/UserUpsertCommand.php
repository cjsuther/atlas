<?php

namespace App\Console\Commands;

use App\Models\Sector;
use App\Models\UserRole;
use App\Models\UsuarioPermiso;
use App\Support\SectorTree;
use Illuminate\Console\Command;

class UserUpsertCommand extends Command
{
    protected $signature = 'atlas:user
                            {username        : Username (login) del usuario}
                            {--password=     : Contraseña en texto plano (se guarda hasheada). Si se omite, se pedirá interactivamente}
                            {--admin         : Administrador del sistema: ve y opera sobre todo, y administra la configuración}
                            {--nodo=         : ID o nombre del nodo del árbol sobre el que se le da permiso}
                            {--nivel=escritura : Nivel del permiso sobre ese nodo: lectura | escritura}
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

        $nivel = (string) $this->option('nivel');
        if (!in_array($nivel, UsuarioPermiso::NIVELES, true)) {
            $this->error("Nivel inválido: {$nivel}. Use " . implode(' | ', UsuarioPermiso::NIVELES) . '.');
            return self::FAILURE;
        }

        $user  = UserRole::firstOrNew(['username' => $username]);
        $isNew = !$user->exists;

        $user->es_admin = $this->option('admin') ? true : (bool) $user->es_admin;
        $user->activo   = $this->option('inactivo') ? 0 : 1;

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
                $user->password = $password; // el cast 'hashed' lo hashea
            }
        }

        // Origen: 'local' si tiene contraseña, 'ldap' si quedó sin contraseña local.
        $user->auth_source = $user->password ? 'local' : 'ldap';

        $user->save();

        // Permiso sobre una rama del árbol, si se indicó un nodo.
        if ($this->option('nodo') !== null && $this->option('nodo') !== '') {
            $sectorId = $this->resolverNodo();
            if ($sectorId === null) {
                return self::FAILURE;
            }
            $user->permisos()->updateOrCreate(['sector_id' => $sectorId], ['nivel' => $nivel]);
            $this->line("  permiso de {$nivel} sobre " . app(SectorTree::class)->rutaDe($sectorId));
        }

        $this->info(($isNew ? 'Creado' : 'Actualizado')
            . ": {$username} (" . ($user->es_admin ? 'administrador del sistema' : 'usuario')
            . ', activo=' . ($user->activo ? 'sí' : 'no') . ')');

        if (!$user->password) {
            $this->warn('El usuario no tiene contraseña local — sólo podrá ingresar vía LDAP.');
        }

        if (!$user->es_admin && !$user->permisos()->exists()) {
            $this->warn('El usuario no tiene permisos sobre ninguna rama: se autentica, pero no ve nada.');
        }

        return self::SUCCESS;
    }

    /** Resuelve --nodo por ID o por nombre. Devuelve null si no se puede. */
    private function resolverNodo(): ?int
    {
        $valor = (string) $this->option('nodo');

        $sector = ctype_digit($valor)
            ? Sector::find((int) $valor)
            : Sector::where('nombre', $valor)->first();

        if (!$sector) {
            $this->error("No se encontró el nodo «{$valor}» en la estructura.");
            return null;
        }

        return (int) $sector->sector_id;
    }
}
