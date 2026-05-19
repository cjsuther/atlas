<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
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

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $cP = ContratoPrincipal::where('resp1_id', $id)->orWhere('resp2_id', $id)->count();
        if ($cP > 0) {
            $msgs[] = "Esta persona figura como responsable en {$cP} contrato(s) principal(es).";
        }
        $cE = ContratoEjecucion::where('resp1_id', $id)->orWhere('resp2_id', $id)->count();
        if ($cE > 0) {
            $msgs[] = "Esta persona figura como responsable en {$cE} contrato(s) de ejecución.";
        }
        return $msgs;
    }
}
