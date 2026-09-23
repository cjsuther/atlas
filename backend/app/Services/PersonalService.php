<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\ContratoPrincipal;
use App\Models\Personal;
use Illuminate\Database\Eloquent\Builder;

class PersonalService extends BaseCrudService
{
    protected string $modelClass = Personal::class;
    protected array $searchableFields = ['apellido', 'nombre', 'mail', 'interno'];

    protected function baseQuery(): Builder
    {
        return Personal::query()->with('lugarTrabajo:sector_id,nombre');
    }

    /** El lugar de trabajo se muestra por su nombre, que está en la otra tabla. */
    protected function aplicarOrdenPropio(Builder $query, string $campo, string $dir): bool
    {
        if ($campo !== 'lugar_trabajo') {
            return false;
        }

        $query->leftJoin('sector as lugar', 'lugar.sector_id', '=', 'personal.lugar_trabajo_id')
              ->select('personal.*')
              ->orderBy('lugar.nombre', $dir === 'desc' ? 'desc' : 'asc');

        return true;
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $cP = ContratoPrincipal::where('resp1_id', $id)->orWhere('resp2_id', $id)->count();
        if ($cP > 0) {
            $msgs[] = "Esta persona figura como responsable en {$cP} contrato(s) principal(es).";
        }
        $cC = Contrato::where('resp1_id', $id)->orWhere('resp2_id', $id)->count();
        if ($cC > 0) {
            $msgs[] = "Esta persona figura como responsable en {$cC} contrato(s).";
        }
        return $msgs;
    }
}
