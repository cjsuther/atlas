<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\CuentaOperativa;
use App\Models\UserRole;
use App\Models\UsuarioPermiso;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Alcance de visibilidad y edición del usuario.
 *
 * No todos pueden ver los saldos ni los registros de todas las gerencias: la
 * información es reservada. El recorte sale de los permisos que el usuario
 * tiene sobre el árbol de la estructura: a cada uno se le asignan nodos —o la
 * raíz, que es toda la organización— con nivel de lectura o de escritura, y el
 * permiso se hereda hacia abajo. Quien puede escribir, puede ver.
 *
 * Este servicio es la única fuente de verdad de ese recorte y lo aplican las
 * consultas de expedientes y movimientos, el panel de saldos y las
 * exportaciones.
 *
 * Convención: `null` significa "sin recorte" (ve todo).
 */
class AccessScopeService
{
    /** @var array<string, array<int>|null> ramas ya resueltas en este request */
    private array $cache = [];

    public function __construct(protected SectorTree $arbol)
    {
    }

    public function usuario(): ?UserRole
    {
        $user = Auth::user();
        return $user instanceof UserRole ? $user : null;
    }

    /**
     * Nodos que el usuario puede ver: los de sus permisos y todo lo que cuelga
     * de ellos.
     *
     * @return array<int>|null
     */
    public function sectoresVisibles(?UserRole $user = null): ?array
    {
        return $this->rama($user, soloEscritura: false);
    }

    /**
     * Nodos sobre los que el usuario puede operar: los de sus permisos de
     * escritura y todo lo que cuelga de ellos.
     *
     * @return array<int>|null
     */
    public function sectoresEditables(?UserRole $user = null): ?array
    {
        return $this->rama($user, soloEscritura: true);
    }

    /**
     * Resuelve la rama alcanzada por los permisos del usuario.
     *
     * Un permiso sobre la raíz no tiene recorte posible: devuelve null, que es
     * la convención de "todo".
     *
     * @return array<int>|null
     */
    private function rama(?UserRole $user, bool $soloEscritura): ?array
    {
        $user ??= $this->usuario();
        if (!$user) {
            return [];
        }

        $clave = $user->id . ($soloEscritura ? ':w' : ':r');
        if (array_key_exists($clave, $this->cache)) {
            return $this->cache[$clave];
        }

        // El administrador del sistema ve y opera sobre todo.
        if ($user->esAdmin()) {
            return $this->cache[$clave] = null;
        }

        $permisos = $user->permisos()->get(['sector_id', 'nivel']);
        if ($soloEscritura) {
            $permisos = $permisos->where('nivel', UsuarioPermiso::NIVEL_ESCRITURA);
        }

        if ($permisos->contains(fn ($p) => $p->sector_id === null)) {
            return $this->cache[$clave] = null;
        }

        $sectores = [];
        foreach ($permisos as $p) {
            foreach ($this->arbol->ramaDe((int) $p->sector_id) as $id) {
                $sectores[$id] = true;
            }
        }

        return $this->cache[$clave] = array_keys($sectores);
    }

    /**
     * Gerencias de Área alcanzadas por los permisos del usuario.
     *
     * @return array<int>|null
     */
    public function gerenciasArea(?UserRole $user = null): ?array
    {
        $ids = $this->sectoresVisibles($user);
        if ($ids === null) {
            return null;
        }

        $raices = [];
        foreach ($ids as $id) {
            $raiz = $this->arbol->raizDe($id);
            if ($raiz !== null) {
                $raices[$raiz] = true;
            }
        }

        return array_keys($raices);
    }

    /**
     * Recorta una consulta de expedientes a los nodos que el usuario puede ver.
     *
     * @template T of Builder|QueryBuilder
     * @param  T  $query
     * @return T
     */
    public function aplicarAContratos(
        Builder|QueryBuilder $query,
        string $tabla = 'contratos_ejecucion',
        ?UserRole $user = null,
    ): Builder|QueryBuilder {
        $ids = $this->sectoresVisibles($user);
        if ($ids === null) {
            return $query;
        }
        return $query->whereIn("{$tabla}.sector_id", $ids ?: [0]);
    }

    /** Los saldos tienen el mismo alcance que los expedientes. */
    public function aplicarASaldos(
        Builder|QueryBuilder $query,
        string $tabla = 'contratos_ejecucion',
        ?UserRole $user = null,
    ): Builder|QueryBuilder {
        return $this->aplicarAContratos($query, $tabla, $user);
    }

    public function puedeVerContrato(ContratoEjecucion $contrato, ?UserRole $user = null): bool
    {
        $ids = $this->sectoresVisibles($user);
        return $ids === null || in_array((int) $contrato->sector_id, $ids, true);
    }

    /** Modificar un expediente exige escritura sobre su rama. */
    public function puedeEditarContrato(ContratoEjecucion $contrato, ?UserRole $user = null): bool
    {
        $ids = $this->sectoresEditables($user);
        return $ids === null || in_array((int) $contrato->sector_id, $ids, true);
    }

    /**
     * Cuentas operativas que el usuario puede ver, o sobre las que puede operar.
     *
     * @return array<int>|null  null = sin recorte
     */
    public function cuentasVisibles(?UserRole $user = null, bool $soloEscritura = false): ?array
    {
        $sectores = $soloEscritura
            ? $this->sectoresEditables($user)
            : $this->sectoresVisibles($user);

        if ($sectores === null) {
            return null;
        }

        return CuentaOperativa::whereIn('sector_id', $sectores ?: [0])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Imputar un expediente a una cuenta exige escritura sobre su rama. */
    public function puedeUsarCuenta(?int $cuentaId, ?UserRole $user = null): bool
    {
        if ($cuentaId === null) {
            return false;
        }

        $ids = $this->cuentasVisibles($user, soloEscritura: true);
        if ($ids === null) {
            return CuentaOperativa::whereKey($cuentaId)->exists();
        }

        return in_array((int) $cuentaId, $ids, true);
    }

    /** Valida que el usuario pueda operar sobre el nodo indicado. */
    public function puedeUsarSector(?int $sectorId, ?UserRole $user = null): bool
    {
        $ids = $this->sectoresEditables($user);
        if ($ids === null) {
            return $this->arbol->existe($sectorId);
        }
        return $sectorId !== null && in_array((int) $sectorId, $ids, true);
    }
}
