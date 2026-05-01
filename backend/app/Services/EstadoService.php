<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\Estado;

class EstadoService extends BaseCrudService
{
    protected string $modelClass = Estado::class;
    protected array $searchableFields = ['estado_nombre', 'descripcion'];

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        $count = Contrato::where('estado_id', $id)->count();
        if ($count > 0) {
            $msgs[] = "Existen {$count} contrato(s) asociado(s) a este estado.";
        }
        return $msgs;
    }
}
