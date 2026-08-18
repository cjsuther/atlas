<?php

namespace App\Http\Controllers;

use App\Services\GerenciaAreaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GerenciaAreaController extends CrudController
{
    public function __construct(GerenciaAreaService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:200', Rule::unique('gerencias_area', 'nombre')],
            'sigla'       => ['nullable', 'string', 'max:50', Rule::unique('gerencias_area', 'sigla')],
            'responsable' => ['nullable', 'string', 'max:200'],
            'activo'      => ['sometimes', 'boolean'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:200', Rule::unique('gerencias_area', 'nombre')->ignore($id)],
            'sigla'       => ['nullable', 'string', 'max:50', Rule::unique('gerencias_area', 'sigla')->ignore($id)],
            'responsable' => ['nullable', 'string', 'max:200'],
            'activo'      => ['sometimes', 'boolean'],
        ];
    }

    protected function validationMessages(): array
    {
        return [
            'nombre.required' => 'El nombre de la Gerencia de Área es obligatorio.',
            'nombre.unique'   => 'Ya existe una Gerencia de Área con ese nombre.',
            'sigla.unique'    => 'Ya existe una Gerencia de Área con esa sigla.',
        ];
    }
}
