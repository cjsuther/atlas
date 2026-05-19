<?php

namespace App\Services;

use App\Models\EjecucionMovimiento;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class EjecucionMovimientoService
{
    /** Disk del filesystem donde se persisten las facturas. */
    public const FACTURA_DISK = 'local';
    public const FACTURA_DIR  = 'facturas';

    public function listForContrato(int $contratoEjecucionId, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), 200));

        $q = EjecucionMovimiento::query()
            ->where('contrato_ejecucion_id', $contratoEjecucionId);

        if (!empty($filters['tipo'])) {
            $q->where('tipo', $filters['tipo']);
        }
        if (!empty($filters['mostrar_baja'])) {
            $q->withTrashed();
        }

        return $q->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function find(int $id, bool $withTrashed = false): ?EjecucionMovimiento
    {
        $q = EjecucionMovimiento::query();
        if ($withTrashed) $q->withTrashed();
        return $q->find($id);
    }

    public function create(int $contratoEjecucionId, array $data, ?UploadedFile $factura = null): EjecucionMovimiento
    {
        $data = $this->normalizeMontos($data);
        $data['contrato_ejecucion_id'] = $contratoEjecucionId;

        if ($factura && ($data['tipo'] ?? null) === 'ingreso') {
            $stored = $this->storeFactura($factura);
            $data = array_merge($data, $stored);
        }

        $m = new EjecucionMovimiento();
        $m->fill($data);
        $m->save();
        return $m->fresh();
    }

    public function update(int $id, array $data, ?UploadedFile $factura = null, bool $eliminarFactura = false): ?EjecucionMovimiento
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return null;

        $data = $this->normalizeMontos($data);

        if ($factura) {
            // Reemplazo de factura: borra la vieja si había
            if ($m->getRawOriginal('factura_path')) {
                Storage::disk(self::FACTURA_DISK)->delete($m->getRawOriginal('factura_path'));
            }
            $data = array_merge($data, $this->storeFactura($factura));
        } elseif ($eliminarFactura) {
            if ($m->getRawOriginal('factura_path')) {
                Storage::disk(self::FACTURA_DISK)->delete($m->getRawOriginal('factura_path'));
            }
            $data['factura_path']          = null;
            $data['factura_original_name'] = null;
            $data['factura_mime']          = null;
        }

        $m->fill($data);
        $m->save();
        return $m->fresh();
    }

    public function softDelete(int $id): bool
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return false;
        return (bool) $m->delete();
    }

    /**
     * Calcula `monto` (en pesos) cuando moneda='Dólar'.
     * Limpia campos USD cuando moneda='Peso'.
     */
    private function normalizeMontos(array $data): array
    {
        $moneda = $data['moneda'] ?? null;
        if ($moneda === 'Dólar') {
            $usd = (float) ($data['monto_dolares'] ?? 0);
            $cot = (float) ($data['cotizacion'] ?? 0);
            $data['monto'] = round($usd * $cot, 2);
        } else {
            $data['monto_dolares'] = null;
            $data['cotizacion']    = null;
        }
        return $data;
    }

    private function storeFactura(UploadedFile $factura): array
    {
        $original = $factura->getClientOriginalName();
        $mime     = $factura->getMimeType();
        $path     = $factura->store(self::FACTURA_DIR, self::FACTURA_DISK);
        return [
            'factura_path'          => $path,
            'factura_original_name' => $original,
            'factura_mime'          => $mime,
        ];
    }
}
