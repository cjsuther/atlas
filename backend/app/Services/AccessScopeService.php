<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\UserRole;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Alcance de visibilidad y edición según el rol del usuario.
 *
 * No todos los usuarios pueden ver los saldos ni los registros de todas las
 * gerencias: la información es reservada y no puede salir de la Gerencia de
 * Área. El usuario se asocia a una Gerencia de Área (un sector sin dependencia)
 * y ve los contratos de todos los subsectores que cuelgan de ella.
 *
 * Este servicio es la única fuente de verdad de ese recorte y lo aplican tanto
 * las consultas de contratos y movimientos como el panel de saldos.
 *
 * Convención: `null` significa "sin recorte" (ve todo).
 */
class AccessScopeService
{
    public function __construct(protected SectorTree $arbol)
    {
    }

    public function usuario(): ?UserRole
    {
        $user = Auth::user();
        return $user instanceof UserRole ? $user : null;
    }

    /**
     * Sectores cuyos contratos el usuario puede ver y editar: su Gerencia de
     * Área y todos los subsectores que dependen de ella.
     *
     * @return array<int>|null
     */
    public function sectoresVisibles(?UserRole $user = null): ?array
    {
        $user ??= $this->usuario();
        if (!$user || $user->veTodo()) {
            return null;
        }
        return $user->sector_id ? $this->arbol->ramaDe((int) $user->sector_id) : [];
    }

    /**
     * Gerencias de Área visibles. Para los roles acotados es una sola: la suya.
     *
     * @return array<int>|null
     */
    public function gerenciasArea(?UserRole $user = null): ?array
    {
        $user ??= $this->usuario();
        if (!$user || $user->veTodo()) {
            return null;
        }
        return $user->sector_id ? [(int) $user->sector_id] : [];
    }

    /**
     * Recorta una consulta de contratos a los sectores permitidos.
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

    /**
     * Los saldos tienen el mismo alcance que los contratos: el límite es la
     * Gerencia de Área.
     */
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

    /**
     * Un usuario acotado puede crear y modificar contratos dentro de su
     * Gerencia de Área; el administrador de sistema, de cualquiera.
     */
    public function puedeEditarContrato(ContratoEjecucion $contrato, ?UserRole $user = null): bool
    {
        return $this->puedeVerContrato($contrato, $user);
    }

    /** Valida que el usuario pueda imputar un contrato al sector indicado. */
    public function puedeUsarSector(?int $sectorId, ?UserRole $user = null): bool
    {
        $ids = $this->sectoresVisibles($user);
        if ($ids === null) {
            return $this->arbol->existe($sectorId);
        }
        return $sectorId !== null && in_array((int) $sectorId, $ids, true);
    }

    /**
     * Roles que el usuario puede asignar al crear o editar usuarios.
     *
     * @return array<int, string>
     */
    public function rolesAsignables(?UserRole $user = null): array
    {
        $user ??= $this->usuario();
        if ($user && $user->isAdminSistema()) {
            return UserRole::ROLES;
        }
        if ($user && $user->isAdminGerencia()) {
            return [UserRole::ROL_OPERADOR_GERENCIA];
        }
        return [];
    }
}
