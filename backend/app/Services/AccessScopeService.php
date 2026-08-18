<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\Gerencia;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Alcance de visibilidad y edición según el rol del usuario.
 *
 * No todos los usuarios pueden ver los saldos ni los registros de todas las
 * gerencias: la información es reservada y no puede salir de la Gerencia de
 * Área. Este servicio es la única fuente de verdad de ese recorte y lo aplican
 * tanto las consultas de contratos y movimientos como el panel de saldos.
 *
 * Convención: `null` significa "sin recorte" (ve todo).
 */
class AccessScopeService
{
    public function usuario(): ?UserRole
    {
        $user = Auth::user();
        return $user instanceof UserRole ? $user : null;
    }

    /**
     * Gerencias cuyos contratos el usuario puede ver y editar.
     *
     * @return array<int>|null
     */
    public function gerenciasDeContratos(?UserRole $user = null): ?array
    {
        $user ??= $this->usuario();
        if (!$user || $user->veTodo()) {
            return null;
        }
        return $user->gerencia_id ? [(int) $user->gerencia_id] : [];
    }

    /**
     * Gerencias cuyos saldos el usuario puede ver.
     *
     * El administrador de gerencia ve los saldos de su Gerencia de Área además
     * de los de su gerencia; el operador ve sólo los de la suya.
     *
     * @return array<int>|null
     */
    public function gerenciasDeSaldos(?UserRole $user = null): ?array
    {
        $user ??= $this->usuario();
        if (!$user || $user->veTodo()) {
            return null;
        }
        if (!$user->gerencia_id) {
            return [];
        }
        if ($user->isAdminGerencia()) {
            $areaId = Gerencia::whereKey($user->gerencia_id)->value('gerencia_area_id');
            if ($areaId) {
                return Gerencia::where('gerencia_area_id', $areaId)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }
        return [(int) $user->gerencia_id];
    }

    /** Gerencias de Área visibles (null = todas). @return array<int>|null */
    public function gerenciasArea(?UserRole $user = null): ?array
    {
        $ids = $this->gerenciasDeSaldos($user);
        if ($ids === null) {
            return null;
        }
        return Gerencia::whereIn('id', $ids)
            ->pluck('gerencia_area_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Recorta una consulta de contratos a las gerencias permitidas.
     *
     * @template T of Builder|QueryBuilder
     * @param  T  $query
     * @return T
     */
    public function aplicarAContratos(Builder|QueryBuilder $query, string $tabla = 'contratos_ejecucion', ?UserRole $user = null): Builder|QueryBuilder
    {
        $ids = $this->gerenciasDeContratos($user);
        if ($ids === null) {
            return $query;
        }
        return $query->whereIn("{$tabla}.gerencia_id", $ids ?: [0]);
    }

    /** Recorta una consulta de contratos a las gerencias con saldos visibles. */
    public function aplicarASaldos(Builder|QueryBuilder $query, string $tabla = 'contratos_ejecucion', ?UserRole $user = null): Builder|QueryBuilder
    {
        $ids = $this->gerenciasDeSaldos($user);
        if ($ids === null) {
            return $query;
        }
        return $query->whereIn("{$tabla}.gerencia_id", $ids ?: [0]);
    }

    public function puedeVerContrato(ContratoEjecucion $contrato, ?UserRole $user = null): bool
    {
        $ids = $this->gerenciasDeContratos($user);
        return $ids === null || in_array((int) $contrato->gerencia_id, $ids, true);
    }

    /**
     * Un usuario acotado a una gerencia puede crear y modificar contratos de esa
     * gerencia; el administrador de sistema, de cualquiera.
     */
    public function puedeEditarContrato(ContratoEjecucion $contrato, ?UserRole $user = null): bool
    {
        return $this->puedeVerContrato($contrato, $user);
    }

    /** Valida que el usuario pueda imputar un contrato a la gerencia indicada. */
    public function puedeUsarGerencia(?int $gerenciaId, ?UserRole $user = null): bool
    {
        $ids = $this->gerenciasDeContratos($user);
        if ($ids === null) {
            return true;
        }
        return $gerenciaId !== null && in_array((int) $gerenciaId, $ids, true);
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
