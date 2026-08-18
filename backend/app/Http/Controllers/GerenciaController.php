<?php

namespace App\Http\Controllers;

use App\Services\GerenciaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GerenciaController extends CrudController
{
    public function __construct(GerenciaService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return $this->rules($request, null);
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        return $this->rules($request, $id);
    }

    /** El nombre de la gerencia es único dentro de su Gerencia de Área. */
    private function rules(Request $request, int|string|null $id): array
    {
        $unique = Rule::unique('gerencias', 'nombre')
            ->where(fn ($q) => $q->where('gerencia_area_id', $request->input('gerencia_area_id')));
        if ($id !== null) {
            $unique->ignore($id);
        }

        return [
            'gerencia_area_id' => ['required', 'integer', 'exists:gerencias_area,id'],
            'nombre'           => ['required', 'string', 'max:200', $unique],
            'sigla'            => ['nullable', 'string', 'max:50'],
            'responsable'      => ['nullable', 'string', 'max:200'],
            'activo'           => ['sometimes', 'boolean'],
        ];
    }

    protected function validationMessages(): array
    {
        return [
            'gerencia_area_id.required' => 'Debe indicar a qué Gerencia de Área pertenece.',
            'nombre.required'           => 'El nombre de la gerencia es obligatorio.',
            'nombre.unique'             => 'Ya existe una gerencia con ese nombre en esa Gerencia de Área.',
        ];
    }
}
