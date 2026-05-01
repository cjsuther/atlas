<?php

namespace App\Http\Controllers;

use App\Services\BaseCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Controlador CRUD genérico para entidades maestras.
 * Las subclases inyectan el servicio y proveen las reglas de validación
 * vía rulesForStore() y rulesForUpdate().
 */
abstract class CrudController extends Controller
{
    protected BaseCrudService $service;

    /** @return array<string, mixed> */
    abstract protected function rulesForStore(Request $request): array;

    /** @return array<string, mixed> */
    abstract protected function rulesForUpdate(Request $request, int|string $id): array;

    /** @return array<string, string> */
    protected function validationMessages(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->paginate($request->all());
        return response()->json($paginator);
    }

    public function show(int|string $id): JsonResponse
    {
        $item = $this->service->find($id);
        if (!$item) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Recurso no encontrado.',
            ], 404);
        }
        return response()->json(['data' => $item]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make(
            $request->all(),
            $this->rulesForStore($request),
            $this->validationMessages()
        )->validate();

        $item = $this->service->create($data);
        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        if (!$this->service->find($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Recurso no encontrado.',
            ], 404);
        }

        $data = Validator::make(
            $request->all(),
            $this->rulesForUpdate($request, $id),
            $this->validationMessages()
        )->validate();

        $item = $this->service->update($id, $data);
        return response()->json(['data' => $item]);
    }

    public function destroy(int|string $id): JsonResponse
    {
        if (!$this->service->find($id)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Recurso no encontrado.',
            ], 404);
        }

        $deps = $this->service->dependenciesFor($id);
        if (!empty($deps)) {
            return response()->json([
                'error'    => 'has_dependencies',
                'message'  => 'No se puede eliminar porque existen dependencias.',
                'detalles' => $deps,
            ], 409);
        }

        $this->service->delete($id);
        return response()->json(['message' => 'Eliminado correctamente.']);
    }
}
