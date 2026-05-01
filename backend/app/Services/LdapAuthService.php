<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use LdapRecord\Connection;
use LdapRecord\Container;

/**
 * Servicio de autenticación contra el AD/LDAP institucional.
 *
 * Estrategia:
 *  1) Bind con la cuenta de servicio para localizar el DN del usuario por su
 *     atributo de identificación (sAMAccountName en AD por defecto).
 *  2) Bind con el DN encontrado y la contraseña ingresada por el usuario.
 *  3) Si ambos binds tienen éxito, devolver los datos del usuario.
 *
 * El sistema NO almacena ni transmite contraseñas: sólo hace los binds y descarta
 * la credencial inmediatamente.
 */
class LdapAuthService
{
    public function __construct(
        protected ?Connection $connection = null
    ) {
        $this->connection ??= Container::getDefaultConnection();
    }

    /**
     * Autentica al usuario contra el LDAP/AD.
     *
     * @return array{username: string, dn: string, display_name: ?string, email: ?string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        try {
            // 1. Conectar con la cuenta de servicio
            $this->connection->connect();
        } catch (\Throwable $e) {
            Log::error('ATLAS LDAP: error conectando con cuenta de servicio', [
                'msg' => $e->getMessage(),
            ]);
            return null;
        }

        // 2. Buscar el usuario por el atributo configurado
        $userAttribute = config('ldap.user_attribute', 'sAMAccountName');

        try {
            $user = $this->connection
                ->query()
                ->where($userAttribute, '=', $username)
                ->first();
        } catch (\Throwable $e) {
            Log::error('ATLAS LDAP: error buscando usuario', [
                'username' => $username,
                'msg'      => $e->getMessage(),
            ]);
            return null;
        }

        if (!$user || !is_array($user) || empty($user['dn'])) {
            Log::info('ATLAS LDAP: usuario no encontrado', ['username' => $username]);
            return null;
        }

        $dn = is_array($user['dn']) ? ($user['dn'][0] ?? null) : $user['dn'];
        if (!$dn) {
            return null;
        }

        // 3. Bind con las credenciales del usuario para validar la contraseña
        try {
            $userConnection = new Connection(array_merge(
                config('ldap.connections.default'),
                [
                    'username' => $dn,
                    'password' => $password,
                ]
            ));
            $userConnection->connect();
        } catch (\Throwable $e) {
            Log::info('ATLAS LDAP: bind de usuario rechazado', [
                'username' => $username,
            ]);
            return null;
        }

        return [
            'username'     => $username,
            'dn'           => $dn,
            'display_name' => $this->firstAttr($user, ['displayname', 'cn', 'name']) ?? $username,
            'email'        => $this->firstAttr($user, ['mail', 'userprincipalname']),
        ];
    }

    private function firstAttr(array $entry, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($entry[$k])) {
                $val = $entry[$k];
                if (is_array($val)) {
                    return $val[0] ?? null;
                }
                return is_string($val) ? $val : null;
            }
        }
        return null;
    }
}
