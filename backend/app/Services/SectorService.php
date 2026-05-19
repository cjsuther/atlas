<?php

namespace App\Services;

use App\Models\Personal;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Builder;

class SectorService extends BaseCrudService
{
    protected string $modelClass = Sector::class;
    protected array $searchableFields = ['nombre', 'responsable', 'ubicacion'];

    protected function baseQuery(): Builder
    {
        return Sector::query()->with('dependencia:sector_id,nombre');
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        if (Sector::where('dependencia_id', $id)->exists()) {
            $msgs[] = 'Existen sectores que dependen de éste.';
        }
        $pers = Personal::where('lugar_trabajo_id', $id)->count();
        if ($pers > 0) {
            $msgs[] = "Existen {$pers} persona(s) con lugar de trabajo en este sector.";
        }
        return $msgs;
    }
}
