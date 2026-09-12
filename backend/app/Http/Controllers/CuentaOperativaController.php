<?php

namespace App\Http\Controllers;

use App\Services\CuentaOperativaService;
use App\Support\SectorTree;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CuentaOperativaController extends CrudController
{
    public function __construct(CuentaOperativaService $service)
    {
        $this->service = $service;
    }

    protected function rulesForStore(Request $request): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:200',
                              Rule::unique('cuentas_operativas', 'nombre')
                                  ->where(fn ($q) => $q->where('sector_id', $request->input('sector_id')))],
            // Null = cuenta de toda la organización.
            'sector_id'   => ['nullable', 'integer', 'exists:sector,sector_id'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activo'      => ['nullable', 'boolean'],
        ];
    }

    protected function rulesForUpdate(Request $request, int|string $id): array
    {
        $rules = $this->rulesForStore($request);
        $rules['nombre'][3] = Rule::unique('cuentas_operativas', 'nombre')
            ->where(fn ($q) => $q->where('sector_id', $request->input('sector_id')))
            ->ignore($id);
        return $rules;
    }

    protected function validationMessages(): array
    {
        return [
            'nombre.unique' => 'Ya existe una cuenta operativa con ese nombre en el mismo nodo.',
        ];
    }

    /** Árbol de la estructura con sus cuentas, para los selectores. */
    public function arbol(SectorTree $arbol): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->service->arbolConCuentas($arbol)]);
    }
}
