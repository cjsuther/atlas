<?php

namespace App\Services;

use App\Models\EjecucionMovimiento;
use App\Models\Expediente;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EjecucionMovimientoService
{
    /** Lo que se carga junto al movimiento para mostrarlo en pantalla. */
    private const RELACIONES = [
        'cuenta:id,nombre,sector_id',
        'cuentaContraparte:id,nombre,sector_id',
        'expediente:id,nro_expediente,sector_id',
    ];

    /** Disk del filesystem donde se persisten las facturas. */
    public const FACTURA_DISK = 'local';
    public const FACTURA_DIR  = 'facturas';

    /** Campos que se replican en la contrapartida de una transferencia. */
    private const CAMPOS_ESPEJO = [
        'nro_expediente', 'moneda', 'monto', 'monto_dolares', 'cotizacion', 'objeto',
        'expediente_id',
    ];

    /** Evita que la sincronización de la contrapartida se dispare a sí misma. */
    private bool $sincronizando = false;

    /** Historial de una cuenta: todo lo que se registró contra ella. */
    public function listForCuenta(int $cuentaOperativaId, array $filters): LengthAwarePaginator
    {
        return $this->listar(
            EjecucionMovimiento::query()->where('cuenta_operativa_id', $cuentaOperativaId),
            $filters,
        );
    }

    /** Movimientos que declaran a este contrato como contrato relacionado. */
    public function listForContrato(int $expedienteId, array $filters): LengthAwarePaginator
    {
        return $this->listar(
            EjecucionMovimiento::query()->where('expediente_id', $expedienteId),
            $filters,
        );
    }

    /** @param \Illuminate\Database\Eloquent\Builder $q */
    private function listar($q, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), 200));

        $q->with(self::RELACIONES);

        if (!empty($filters['tipo'])) {
            $q->where('tipo', $filters['tipo']);
        }
        if (!empty($filters['accion'])) {
            $q->where('accion', $filters['accion']);
        }
        if (!empty($filters['mostrar_baja'])) {
            $q->withTrashed();
        }

        return $q->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function find(int $id, bool $withTrashed = false): ?EjecucionMovimiento
    {
        $q = EjecucionMovimiento::query()->with(self::RELACIONES);
        if ($withTrashed) $q->withTrashed();
        return $q->find($id);
    }

    public function create(int $cuentaOperativaId, array $data, ?UploadedFile $factura = null): EjecucionMovimiento
    {
        $data = $this->conNumeroDeExpediente($data);
        $data = $this->normalizeMontos($data);
        $data = $this->normalizeContraparte($data);
        $data['cuenta_operativa_id'] = $cuentaOperativaId;

        if ($factura && $this->admiteFactura($data)) {
            $data = array_merge($data, $this->storeFactura($factura));
        }

        return DB::transaction(function () use ($data) {
            $m = new EjecucionMovimiento();
            $m->fill($data);
            $m->save();

            if ($this->esTransferencia($m)) {
                $this->crearEspejo($m);
            }

            return $m->fresh();
        });
    }

    public function update(int $id, array $data, ?UploadedFile $factura = null, bool $eliminarFactura = false): ?EjecucionMovimiento
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return null;

        $data = $this->conNumeroDeExpediente($data);
        $data = $this->normalizeMontos($data);
        $data = $this->normalizeContraparte($data);

        if ($factura) {
            // Reemplazo de factura: borra la vieja si había
            $this->borrarFactura($m);
            $data = array_merge($data, $this->storeFactura($factura));
        } elseif ($eliminarFactura) {
            $this->borrarFactura($m);
            $data['factura_path']          = null;
            $data['factura_original_name'] = null;
            $data['factura_mime']          = null;
        }

        return DB::transaction(function () use ($m, $data) {
            $m->fill($data);
            $m->save();

            $this->sincronizarEspejo($m);

            return $m->fresh();
        });
    }

    public function softDelete(int $id): bool
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return false;

        return (bool) DB::transaction(function () use ($m) {
            $espejo = $m->movimiento_espejo_id
                ? EjecucionMovimiento::find($m->movimiento_espejo_id)
                : null;

            $ok = $m->delete();

            // La contrapartida deja de tener sentido sin su movimiento original.
            if ($espejo && !$this->sincronizando) {
                $this->sincronizando = true;
                $espejo->delete();
                $this->sincronizando = false;
            }

            return $ok;
        });
    }

    // ------------------------------------------------------------------
    // Transferencias entre contratos
    // ------------------------------------------------------------------

    private function esTransferencia(EjecucionMovimiento $m): bool
    {
        return $m->accion === EjecucionMovimiento::ACCION_TRANSFERENCIA
            && !empty($m->cuenta_contraparte_id);
    }

    /**
     * Una transferencia mueve fondos entre dos cuentas: lo que sale de una
     * entra en la otra. Se registra automáticamente la contrapartida para que
     * los saldos de las dos queden consistentes.
     */
    private function crearEspejo(EjecucionMovimiento $origen): void
    {
        if ($this->sincronizando || $origen->movimiento_espejo_id) return;

        $this->sincronizando = true;
        try {
            $espejo = new EjecucionMovimiento();
            $espejo->fill([
                'cuenta_operativa_id'     => $origen->cuenta_contraparte_id,
                'expediente_id'   => $origen->expediente_id,
                'tipo'                    => $origen->tipo === 'gasto' ? 'ingreso' : 'gasto',
                'accion'                  => EjecucionMovimiento::ACCION_TRANSFERENCIA,
                'contraparte_tipo'        => 'cuenta',
                'cuenta_contraparte_id'   => $origen->cuenta_operativa_id,
                'movimiento_espejo_id'    => $origen->id,
                'nro_expediente'          => $origen->nro_expediente,
                'moneda'                  => $origen->moneda,
                'monto'                   => $origen->monto,
                'monto_dolares'           => $origen->monto_dolares,
                'cotizacion'              => $origen->cotizacion,
                'objeto'                  => $origen->objeto,
            ]);
            $espejo->save();

            $origen->movimiento_espejo_id = $espejo->id;
            $origen->save();
        } finally {
            $this->sincronizando = false;
        }
    }

    /** Propaga a la contrapartida los cambios del movimiento original. */
    private function sincronizarEspejo(EjecucionMovimiento $m): void
    {
        if ($this->sincronizando) return;

        // Dejó de ser transferencia: la contrapartida se da de baja.
        if (!$this->esTransferencia($m)) {
            if ($m->movimiento_espejo_id) {
                $this->sincronizando = true;
                EjecucionMovimiento::find($m->movimiento_espejo_id)?->delete();
                $this->sincronizando = false;
                $m->movimiento_espejo_id = null;
                $m->save();
            }
            return;
        }

        if (!$m->movimiento_espejo_id) {
            $this->crearEspejo($m);
            return;
        }

        $espejo = EjecucionMovimiento::find($m->movimiento_espejo_id);
        if (!$espejo) {
            $m->movimiento_espejo_id = null;
            $this->crearEspejo($m);
            return;
        }

        $this->sincronizando = true;
        try {
            foreach (self::CAMPOS_ESPEJO as $campo) {
                $espejo->{$campo} = $m->{$campo};
            }
            $espejo->tipo                  = $m->tipo === 'gasto' ? 'ingreso' : 'gasto';
            $espejo->cuenta_operativa_id   = $m->cuenta_contraparte_id;
            $espejo->cuenta_contraparte_id = $m->cuenta_operativa_id;
            $espejo->save();
        } finally {
            $this->sincronizando = false;
        }
    }

    // ------------------------------------------------------------------
    // Normalización
    // ------------------------------------------------------------------

    /**
     * El número de expediente es el del expediente elegido: se copia para poder
     * mostrarlo y buscarlo sin ir a buscar la relación.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function conNumeroDeExpediente(array $data): array
    {
        if (!empty($data['expediente_id'])) {
            $numero = Expediente::withTrashed()->find((int) $data['expediente_id'])?->nro_expediente;
            if ($numero !== null) {
                $data['nro_expediente'] = $numero;
            }
        }

        return $data;
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

    /**
     * Deriva `contraparte_tipo` de la acción y limpia los campos que no aplican,
     * para que no queden datos de una contraparte que ya no corresponde.
     */
    private function normalizeContraparte(array $data): array
    {
        $accion = $data['accion'] ?? EjecucionMovimiento::ACCION_FACTURA;
        $tipo   = $data['tipo']   ?? 'gasto';

        $data['contraparte_tipo'] = match ($accion) {
            EjecucionMovimiento::ACCION_TRANSFERENCIA => 'cuenta',
            EjecucionMovimiento::ACCION_INCENTIVO,
            EjecucionMovimiento::ACCION_MCH           => 'rubro',
            default                                   => $tipo === 'ingreso' ? 'cliente' : 'proveedor',
        };

        $vigentes = [
            'cliente'   => 'cliente',
            'proveedor' => 'proveedor',
            'rubro'     => 'rubro',
            'cuenta'    => 'cuenta_contraparte_id',
        ];
        foreach ($vigentes as $tipoContraparte => $campo) {
            if ($tipoContraparte !== $data['contraparte_tipo']) {
                $data[$campo] = null;
            }
        }

        return $data;
    }

    /** Sólo los gastos por factura llevan archivo adjunto. */
    private function admiteFactura(array $data): bool
    {
        return ($data['accion'] ?? null) === EjecucionMovimiento::ACCION_FACTURA
            && ($data['tipo'] ?? null) === 'gasto';
    }

    private function borrarFactura(EjecucionMovimiento $m): void
    {
        $path = $m->getRawOriginal('factura_path');
        if ($path) {
            Storage::disk(self::FACTURA_DISK)->delete($path);
        }
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
