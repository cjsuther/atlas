<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema.
 *
 * El alcance no es un rol: son asignaciones sobre el árbol de la estructura.
 * A cada usuario se le da uno o más nodos —o la raíz, que es toda la
 * organización— con nivel de lectura o de escritura, y el permiso se hereda
 * hacia abajo. Quien puede escribir, puede ver.
 *
 * Aparte del árbol está `es_admin`, que habilita la configuración del sistema:
 * estructura, cuentas, catálogos, usuarios y respaldos.
 *
 * Un usuario sin asignaciones y sin el atributo de administrador se autentica
 * y nada más: es el estado con el que se dan de alta los que llegan por LDAP,
 * hasta que un administrador les asigne permisos.
 */
class UserRole extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /** Niveles del árbol a los que puede abrirse la tabla de saldos. */
    public const AGRUPACIONES_SALDO = ['gerencia_area', 'gerencia', 'contrato'];

    protected $table = 'user_roles';

    protected $fillable = [
        'username',
        'display_name',
        'email',
        'password',
        'auth_source',
        'es_admin',
        'saldos_agrupacion',
        'activo',
        'last_login',
    ];

    protected $casts = [
        'es_admin'   => 'boolean',
        'activo'     => 'boolean',
        'last_login' => 'datetime',
        'password'   => 'hashed',
    ];

    protected $hidden = [
        'password',
    ];

    /** Permisos del usuario sobre el árbol de la estructura. */
    public function permisos()
    {
        return $this->hasMany(UsuarioPermiso::class, 'user_role_id');
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

    /** Administra la configuración del sistema. */
    public function esAdmin(): bool
    {
        return (bool) $this->es_admin;
    }

    /**
     * Sin acceso: se autentica, pero no tiene ni un permiso sobre el árbol.
     * Es el estado inicial de quien llega por LDAP.
     */
    public function sinAcceso(): bool
    {
        return !$this->esAdmin() && !$this->permisos()->exists();
    }

    /** Puede crear y modificar usuarios. */
    public function puedeAdministrarUsuarios(): bool
    {
        return $this->esAdmin();
    }

    /**
     * true si el usuario no está acotado a una rama: es administrador o tiene
     * un permiso sobre la raíz del árbol.
     */
    public function veTodo(): bool
    {
        return $this->esAdmin() || $this->permisos()->whereNull('sector_id')->exists();
    }

    /** true si puede escribir en toda la organización. */
    public function escribeEnTodo(): bool
    {
        return $this->permisos()
            ->whereNull('sector_id')
            ->where('nivel', UsuarioPermiso::NIVEL_ESCRITURA)
            ->exists();
    }
}
