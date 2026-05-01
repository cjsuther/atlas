<?php

namespace App\Http\Controllers;

use App\Services\EstadoService;
use Illuminate\Http\Request;

class EstadoController extends CrudController
{
    public function __construct(EstadoService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'estado_nombre' => ['required', 'string', 'max:100'],
            'descripcion'   => ['nullable', 'string'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return $this->rulesForStore($request);
    }
}
