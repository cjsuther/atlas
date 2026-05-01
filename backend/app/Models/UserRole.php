<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class UserRole extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'user_roles';

    protected $fillable = [
        'username',
        'display_name',
        'email',
        'rol',
        'activo',
        'last_login',
    ];

    protected $casts = [
        'activo'     => 'boolean',
        'last_login' => 'datetime',
    ];

    protected $hidden = [
        // Este modelo no maneja contraseñas — vienen del LDAP.
    ];

    public function isAdmin(): bool
    {
        return $this->rol === 'admin';
    }

    public function isOperador(): bool
    {
        return $this->rol === 'operador';
    }

    public function isConsulta(): bool
    {
        return $this->rol === 'consulta';
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->rol, $roles, true);
    }
}
