<?php

namespace App\Services;

use App\Models\Contrato;
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
        $count = Contrato::where('resp1_id', $id)->orWhere('resp2_id', $id)->count();
        if ($count > 0) {
            $msgs[] = "Esta persona figura como responsable en {$count} contrato(s).";
        }
        return $msgs;
    }
}
