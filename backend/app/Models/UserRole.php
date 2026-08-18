<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema, con rol y alcance.
 *
 *   admin_sistema     : ve y administra todas las gerencias de área, gerencias
 *                       y contratos, y crea/modifica usuarios de cualquier rol.
 *   admin_gerencia    : administra los contratos de su gerencia y los usuarios
 *                       operadores de esa gerencia; ve los saldos agregados de
 *                       su Gerencia de Área.
 *   operador_gerencia : administra los contratos de su gerencia y ve sus saldos.
 */
class UserRole extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const ROL_ADMIN_SISTEMA     = 'admin_sistema';
    public const ROL_ADMIN_GERENCIA    = 'admin_gerencia';
    public const ROL_OPERADOR_GERENCIA = 'operador_gerencia';

    public const ROLES = [
        self::ROL_ADMIN_SISTEMA,
        self::ROL_ADMIN_GERENCIA,
        self::ROL_OPERADOR_GERENCIA,
    ];

    /** Roles que sólo operan dentro de su propia gerencia. */
    public const ROLES_CON_GERENCIA = [
        self::ROL_ADMIN_GERENCIA,
        self::ROL_OPERADOR_GERENCIA,
    ];

    public const AGRUPACIONES_SALDO = ['gerencia_area', 'gerencia', 'contrato'];

    protected $table = 'user_roles';

    protected $fillable = [
        'username',
        'display_name',
        'email',
        'password',
        'auth_source',
        'rol',
        'gerencia_id',
        'saldos_agrupacion',
        'activo',
        'last_login',
    ];

    protected $casts = [
        'activo'     => 'boolean',
        'last_login' => 'datetime',
        'password'   => 'hashed',
    ];

    protected $hidden = [
        'password',
    ];

    public function gerencia()
    {
        return $this->belongsTo(Gerencia::class, 'gerencia_id', 'id');
    }

    public function hasLocalPassword(): bool
    {
        return !empty($this->password);
    }

    public function isLocal(): bool
    {
        return $this->auth_source === 'local';
    }

    public function isLdap(): bool
    {
        return $this->auth_source === 'ldap';
    }

    public function isAdminSistema(): bool
    {
        return $this->rol === self::ROL_ADMIN_SISTEMA;
    }

    public function isAdminGerencia(): bool
    {
        return $this->rol === self::ROL_ADMIN_GERENCIA;
    }

    public function isOperadorGerencia(): bool
    {
        return $this->rol === self::ROL_OPERADOR_GERENCIA;
    }

    /** Puede crear y modificar usuarios (de todo el sistema o de su gerencia). */
    public function puedeAdministrarUsuarios(): bool
    {
        return $this->isAdminSistema() || $this->isAdminGerencia();
    }

    /** true si el usuario no está acotado a una gerencia. */
    public function veTodo(): bool
    {
        return $this->isAdminSistema();
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->rol, $roles, true);
    }
}
