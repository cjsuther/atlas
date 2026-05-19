<?php

namespace App\Services;

use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
use App\Models\Uvt;

class UvtService extends BaseCrudService
{
    protected string $modelClass = Uvt::class;
    protected array $searchableFields = ['siglas', 'nombre', 'responsable'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $cP = ContratoPrincipal::where('uvt_id', $id)->count();
        if ($cP > 0) {
            $msgs[] = "Existen {$cP} contrato(s) principal(es) asociado(s) a esta UVT.";
        }
        $cE = ContratoEjecucion::where('uvt_id', $id)->count();
        if ($cE > 0) {
            $msgs[] = "Existen {$cE} contrato(s) de ejecución asociado(s) a esta UVT.";
        }
        return $msgs;
    }
}
