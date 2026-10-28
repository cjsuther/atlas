<?php

namespace App\Support;

use App\Models\EjecucionMovimiento;
use App\Models\HistorialCambio;
use App\Services\AccessScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se hizo sobre un expediente: los cambios del propio expediente y los
 * de los movimientos que lo usan, en un solo listado, del más nuevo al más
 * viejo.
 *
 * El historial guarda columnas e ids tal como están en la base; acá se los
 * traduce a lo que el usuario ve en pantalla: el nombre del campo, el de la
 * cuenta, el número del expediente. Los movimientos de cuentas que el usuario
 * no ve no aparecen, igual que en el resto del sistema.
 */
class HistorialExpediente
{
    /** Columnas que se muestran, con su nombre en pantalla. */
    private const CAMPOS = [
        'nro_expediente'            => 'Número de expediente',
        'cuenta_operativa_id'       => 'Cuenta',
        'tipo'                      => 'Tipo',
        'accion'                    => 'Acción',
        'contraparte_tipo'          => 'Tipo de contraparte',
        'proveedor'                 => 'Proveedor',
        'cliente'                   => 'Cliente',
        'rubro'                     => 'Rubro',
        'expediente_id'             => 'Expediente',
        'expediente_contraparte_id' => 'Expediente de la contraparte',
        'cuenta_contraparte_id'     => 'Cuenta de la contraparte',
        'moneda'                    => 'Moneda',
        'monto'                     => 'Monto',
        'monto_dolares'             => 'Monto en dólares',
        'cotizacion'                => 'Cotización',
        'objeto'                    => 'Objeto',
        'fecha'                     => 'Fecha del comprobante',
        'factura_original_name'     => 'Factura',
        'estado_aprobacion'         => 'Aprobación',
        'aprobacion_observacion'    => 'Observación del aprobador',
        'deleted_at'                => 'Estado',
    ];

    /** Columnas internas o que se deducen de otra: no le dicen nada al usuario. */
    private const OCULTOS = ['sector_id', 'movimiento_espejo_id', 'factura_path', 'factura_mime',
                             'contrato_principal_id', 'created_at', 'cargado_por', 'aprobacion_usuario',
                             'aprobacion_fecha', 'cambio_propuesto', 'cambio_propuesto_por',
                             'cambio_propuesto_fecha', 'distribucion_de_id', 'distribucion_porcentaje'];

    public function __construct(protected AccessScopeService $scope)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function de(int $expedienteId): array
    {
        $visibles = $this->scope->cuentasVisibles();

        $movimientos = EjecucionMovimiento::withTrashed()
            ->where(fn ($q) => $q->where('expediente_id', $expedienteId)
                                 ->orWhere('expediente_contraparte_id', $expedienteId))
            ->when($visibles !== null, fn ($q) => $q->whereIn('cuenta_operativa_id', $visibles ?: [0]))
            ->get()
            ->keyBy('id');

        $cambios = HistorialCambio::query()
            ->where(fn ($q) => $q
                ->where(fn ($e) => $e->where('tabla', 'expedientes')->where('registro_id', $expedienteId))
                ->orWhere(fn ($m) => $m->where('tabla', 'ejecucion_movimientos')
                                       ->whereIn('registro_id', $movimientos->keys()->all() ?: [0])))
            ->where(fn ($q) => $q->whereNull('campo_modificado')
                                 ->orWhereNotIn('campo_modificado', self::OCULTOS))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        $nombres = $this->nombres($cambios, $movimientos);

        return $cambios->map(function (HistorialCambio $h) use ($movimientos, $nombres) {
            $campo = $h->campo_modificado;
            return [
                'id'             => $h->id,
                'fecha'          => $h->fecha?->toIso8601String(),
                'usuario'        => $h->usuario,
                'usuario_nombre' => $nombres['usuarios'][$h->usuario] ?? null,
                'sobre'          => $h->tabla === 'expedientes'
                    ? 'Expediente'
                    : $this->describirMovimiento($movimientos[$h->registro_id] ?? null, $nombres),
                'movimiento_id'  => $h->tabla === 'ejecucion_movimientos' ? $h->registro_id : null,
                'tipo_cambio'    => $h->tipo_cambio,
                'campo'          => $campo === null ? null : (self::CAMPOS[$campo] ?? $campo),
                'valor_anterior' => $this->valor($campo, $h->valor_anterior, $nombres),
                'valor_nuevo'    => $this->valor($campo, $h->valor_nuevo, $nombres),
            ];
        })->all();
    }

    /**
     * Nombres de usuarios, cuentas y expedientes que aparecen en el listado,
     * buscados de una vez.
     *
     * @return array{usuarios: array<string, string>, cuentas: array<int, string>, expedientes: array<int, string>}
     */
    private function nombres(Collection $cambios, Collection $movimientos): array
    {
        $cuentas = $movimientos->pluck('cuenta_operativa_id')->all();
        $expedientes = [];
        foreach ($cambios as $h) {
            foreach ([$h->valor_anterior, $h->valor_nuevo] as $v) {
                if (!ctype_digit((string) $v)) {
                    continue;
                }
                if (in_array($h->campo_modificado, ['cuenta_operativa_id', 'cuenta_contraparte_id'], true)) {
                    $cuentas[] = (int) $v;
                } elseif (in_array($h->campo_modificado, ['expediente_id', 'expediente_contraparte_id'], true)) {
                    $expedientes[] = (int) $v;
                }
            }
        }

        // Una cuenta que el usuario no ve no se nombra.
        $visibles = $this->scope->cuentasVisibles();
        $cuentas = array_values(array_filter(array_unique($cuentas),
            fn ($id) => $visibles === null || in_array((int) $id, $visibles, true)));

        return [
            'usuarios'    => DB::table('user_roles')
                ->whereIn('username', $cambios->pluck('usuario')->unique()->all() ?: [''])
                ->pluck('display_name', 'username')->all(),
            'cuentas'     => DB::table('cuentas_operativas')->whereIn('id', $cuentas ?: [0])
                ->pluck('nombre', 'id')->all(),
            'expedientes' => DB::table('expedientes')->whereIn('id', array_unique($expedientes) ?: [0])
                ->pluck('nro_expediente', 'id')->all(),
        ];
    }

    private function describirMovimiento(?EjecucionMovimiento $m, array $nombres): string
    {
        if ($m === null) {
            return 'Movimiento';
        }
        $partes = ['Movimiento #' . $m->id, ucfirst((string) $m->tipo)];
        if ($m->monto !== null) {
            $partes[] = '$ ' . number_format((float) $m->monto, 2, ',', '.');
        }
        if (isset($nombres['cuentas'][$m->cuenta_operativa_id])) {
            $partes[] = $nombres['cuentas'][$m->cuenta_operativa_id];
        }
        return implode(' · ', $partes);
    }

    private function valor(?string $campo, ?string $v, array $nombres): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        // Cambios grabados antes de que el historial guardara el valor crudo:
        // las fechas quedaron como texto JSON.
        $v = trim($v, '"');
        return match ($campo) {
            'cuenta_operativa_id', 'cuenta_contraparte_id'
                => $nombres['cuentas'][(int) $v] ?? 'Cuenta fuera de su alcance',
            'expediente_id', 'expediente_contraparte_id'
                => $nombres['expedientes'][(int) $v] ?? "Expediente #{$v}",
            'monto', 'monto_dolares'
                => number_format((float) $v, 2, ',', '.'),
            'fecha'
                => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $f) ? "{$f[3]}/{$f[2]}/{$f[1]}" : $v,
            'deleted_at'
                => ['baja' => 'Dado de baja', 'restaurado' => 'Restaurado'][$v] ?? $v,
            'estado_aprobacion'
                => EjecucionMovimiento::ESTADOS_APROBACION[$v] ?? $v,
            default => $v,
        };
    }
}
