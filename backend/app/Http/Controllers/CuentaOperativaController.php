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
            // Toda cuenta cuelga de un nodo del árbol; no hay cuentas sueltas.
            'sector_id'   => ['required', 'integer', 'exists:sector,sector_id'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            // Toda cuenta tiene a alguien que responde por ella.
            'responsable_id' => ['required', 'integer', 'exists:personal,legajo'],
            'saldo_inicial'  => ['nullable', 'numeric'],
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
            'nombre.unique'      => 'Ya existe una cuenta operativa con ese nombre en el mismo nodo.',
            'sector_id.required' => 'Debe indicar de qué nodo de la estructura cuelga la cuenta.',
            'sector_id.exists'   => 'El nodo indicado no existe.',
            'responsable_id.required' => 'Debe indicar el responsable de la cuenta.',
            'responsable_id.exists'   => 'La persona indicada no está en el personal.',
        ];
    }

    /** POST /api/cuentas-operativas/{id}/archivar — body: archivar (bool) */
    public function archivar(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['archivar' => ['required', 'boolean']]);
        $cuenta = $this->service->archivar($id, (bool) $data['archivar']);
        if (!$cuenta) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Recurso no encontrado.',
            ], 404);
        }
        return response()->json(['data' => $cuenta]);
    }

    /** Árbol de la estructura con sus cuentas, para los selectores. */
    public function arbol(SectorTree $arbol): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->service->arbolConCuentas($arbol)]);
    }
}
