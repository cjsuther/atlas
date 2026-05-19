<?php

namespace App\Http\Controllers;

use App\Services\EstadoEjecucionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstadoEjecucionController extends CrudController
{
    public function __construct(EstadoEjecucionService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:100',
                              Rule::unique('estado_ejecucion', 'nombre')],
            'descripcion' => ['nullable', 'string'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:100',
                              Rule::unique('estado_ejecucion', 'nombre')->ignore($id, 'id')],
            'descripcion' => ['nullable', 'string'],
        ];
    }
}
