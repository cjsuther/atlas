<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\Uvt;

class UvtService extends BaseCrudService
{
    protected string $modelClass = Uvt::class;
    protected array $searchableFields = ['siglas', 'nombre', 'responsable'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $count = Contrato::where('uvt_id', $id)->count();
        if ($count > 0) {
            $msgs[] = "Existen {$count} contrato(s) asociado(s) a esta UVT.";
        }
        return $msgs;
    }
}
