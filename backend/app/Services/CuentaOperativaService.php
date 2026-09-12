<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\CuentaOperativa;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;

class CuentaOperativaService extends BaseCrudService
{
    protected string $modelClass = CuentaOperativa::class;
    protected array $searchableFields = ['nombre', 'descripcion'];

    protected function baseQuery(): Builder
    {
        return CuentaOperativa::query()->with('sector:sector_id,nombre,dependencia_id');
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $expedientes = ContratoEjecucion::where('cuenta_operativa_id', $id)->count();
        if ($expedientes > 0) {
            $msgs[] = "Existen {$expedientes} expediente(s) imputado(s) a esta cuenta.";
        }
        return $msgs;
    }

    /**
     * Cuenta homónima de un nodo del árbol. Es la que se crea junto con el nodo
     * para que nunca haya una rama sin cuenta a la que imputar.
     */
    public function crearParaSector(int $sectorId, string $nombre): CuentaOperativa
    {
        return CuentaOperativa::firstOrCreate(
            ['sector_id' => $sectorId, 'nombre' => $nombre],
            ['activo' => true],
        );
    }

    /**
     * El árbol de la estructura con las cuentas de cada nodo, para los
     * selectores: al imputar un expediente se elige una cuenta de cualquier
     * rama, y quien asigna permisos elige un nodo.
     *
     * La raíz es toda la organización; de ella cuelgan las Gerencias de Área.
     */
    public function arbolConCuentas(SectorTree $arbol): array
    {
        $cuentas = CuentaOperativa::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sector_id'])
            ->groupBy(fn ($c) => $c->sector_id === null ? 'raiz' : (string) $c->sector_id);

        $nodo = function (?int $sectorId) use (&$nodo, $arbol, $cuentas): array {
            $clave = $sectorId === null ? 'raiz' : (string) $sectorId;
            $hijos = $sectorId === null ? $arbol->raices() : $arbol->hijosDe($sectorId);

            return [
                'sector_id' => $sectorId,
                'nombre'    => $sectorId === null
                    ? SectorTree::ETIQUETAS[SectorTree::NIVEL_ORGANIZACION]
                    : $arbol->nombre($sectorId),
                'nivel'     => $arbol->nivelDe($sectorId),
                'cuentas'   => ($cuentas[$clave] ?? collect())
                    ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values()->all(),
                'hijos'     => array_map($nodo, $hijos),
            ];
        };

        return $nodo(null);
    }
}
