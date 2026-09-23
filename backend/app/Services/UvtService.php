<?php

namespace App\Services;

use App\Models\Contrato;
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
        $cC = Contrato::where('uvt_id', $id)->count();
        if ($cC > 0) {
            $msgs[] = "Existen {$cC} contrato(s) asociado(s) a esta UVT.";
        }
        return $msgs;
    }
}
