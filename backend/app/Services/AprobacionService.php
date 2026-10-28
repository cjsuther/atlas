<?php

namespace App\Services;

use App\Models\CuentaOperativa;
use App\Models\EjecucionMovimiento;
use App\Models\Expediente;
use App\Models\LogActividad;
use App\Models\UserRole;
use App\Support\RegistroActividad;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja del aprobador: lo que espera aprobación en las cuentas de su
 * alcance, y la decisión de aprobarlo o rechazarlo.
 *
 * Aprueba quien tiene la marca de aprobador y ve la cuenta del movimiento.
 * Nadie aprueba lo que pidió él mismo: el control lo hace otra persona.
 *
 * Lo que espera es de dos clases:
 *  - un alta (o la corrección de algo rechazado): el movimiento pendiente, que
 *    al aprobarse empieza a sumar en el saldo;
 *  - un pedido de modificación o de baja sobre un movimiento aprobado: el
 *    saldo sigue con lo aprobado y el cambio se aplica al aprobarse.
 */
class AprobacionService
{
    /** Campos que se comparan para mostrar qué cambia un pedido de modificación. */
    private const CAMPOS = [
        'tipo'                  => 'Tipo',
        'accion'                => 'Acción',
        'expediente_id'         => 'Expediente',
        'cliente'               => 'Cliente',
        'proveedor'             => 'Proveedor',
        'rubro'                 => 'Rubro',
        'cuenta_contraparte_id' => 'Cuenta de la contraparte',
        'moneda'                => 'Moneda',
        'monto'                 => 'Monto',
        'monto_dolares'         => 'Monto en dólares',
        'cotizacion'            => 'Cotización',
        'objeto'                => 'Objeto',
        'fecha'                 => 'Fecha del comprobante',
    ];

    public function __construct(
        protected AccessScopeService $scope,
        protected EjecucionMovimientoService $movimientos,
        protected SectorTree $arbol,
    ) {
    }

    /**
     * Lo que espera aprobación en las cuentas que el usuario ve.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bandeja(UserRole $user): array
    {
        $items = $this->esperando($user)
            ->with(EjecucionMovimientoService::RELACIONES)
            ->with('cuenta:id,nombre,sector_id')
            ->get();

        $nombres = DB::table('user_roles')
            ->whereIn('username', $items->map(fn ($m) => $this->solicitante($m))->filter()->unique()->all() ?: [''])
            ->pluck('display_name', 'username')->all();

        return $items
            ->map(fn (EjecucionMovimiento $m) => $this->item($m, $user, $nombres))
            ->sortByDesc('solicitado_fecha')
            ->values()
            ->all();
    }

    /** Cuánto espera su decisión: lo que puede aprobar, sin contar lo que pidió él. */
    public function cantidad(UserRole $user): int
    {
        if (!$user->es_aprobador) {
            return 0;
        }
        return $this->esperando($user)
            ->get(['id', 'cargado_por', 'cambio_propuesto', 'cambio_propuesto_por'])
            ->filter(fn ($m) => $this->solicitante($m) !== $user->username)
            ->count();
    }

    public function aprobar(int $id, UserRole $user, ?string $observacion): EjecucionMovimiento
    {
        $m = $this->paraDecidir($id, $user);

        DB::transaction(function () use ($m, $user, $observacion) {
            $decision = [
                'aprobacion_usuario'     => $user->username,
                'aprobacion_fecha'       => now(),
                'aprobacion_observacion' => $this->limpiar($observacion),
            ];
            $pedido = $m->cambio_propuesto;

            if (is_array($pedido) && ($pedido['tipo'] ?? null) === 'baja') {
                RegistroActividad::movimiento(LogActividad::APROBACION, $m,
                    'Aprobó la baja · ' . RegistroActividad::describir($m), $observacion);
                $m->forceFill($decision);
                $m->cambio_propuesto = null;
                $m->save();
                $this->movimientos->darDeBaja($m);
                return;
            }

            if (is_array($pedido)) {
                $antes = RegistroActividad::describir($m);
                // La factura del pedido pasa a ser la del movimiento: no se borra.
                $m->cambio_propuesto       = null;
                $m->cambio_propuesto_por   = null;
                $m->cambio_propuesto_fecha = null;
                $m->forceFill($decision);
                $m->save();
                $m = $this->movimientos->aplicar($m, $pedido['data'] ?? [],
                    $pedido['factura'] ?? null, (bool) ($pedido['eliminar_factura'] ?? false));
                RegistroActividad::movimiento(LogActividad::APROBACION, $m,
                    'Aprobó la modificación · antes: ' . $antes . ' · ahora: ' . RegistroActividad::describir($m),
                    $observacion);
                return;
            }

            $this->movimientos->marcarGrupo($m, EjecucionMovimiento::APROBADO, $decision);
            RegistroActividad::movimiento(LogActividad::APROBACION, $m,
                'Aprobó el alta · ' . RegistroActividad::describir($m), $observacion);
        });

        return EjecucionMovimiento::withTrashed()->find($id);
    }

    public function rechazar(int $id, UserRole $user, string $observacion): EjecucionMovimiento
    {
        $m = $this->paraDecidir($id, $user);

        DB::transaction(function () use ($m, $user, $observacion) {
            $pedido = $m->cambio_propuesto;

            if (is_array($pedido)) {
                // El movimiento sigue como estaba aprobado; lo que se descarta
                // es el pedido.
                $que = ($pedido['tipo'] ?? null) === 'baja' ? 'la baja' : 'la modificación';
                RegistroActividad::movimiento(LogActividad::RECHAZO, $m,
                    "Rechazó {$que} · " . RegistroActividad::describir($m), $observacion);
                $this->movimientos->descartarPedido($m);
                $m->save();
                return;
            }

            $this->movimientos->marcarGrupo($m, EjecucionMovimiento::RECHAZADO, [
                'aprobacion_usuario'     => $user->username,
                'aprobacion_fecha'       => now(),
                'aprobacion_observacion' => $this->limpiar($observacion),
            ]);
            RegistroActividad::movimiento(LogActividad::RECHAZO, $m,
                'Rechazó el alta · ' . RegistroActividad::describir($m), $observacion);
        });

        return EjecucionMovimiento::withTrashed()->find($id);
    }

    // ------------------------------------------------------------------

    /**
     * Movimientos que esperan una decisión: los pendientes —sólo el principal
     * de cada grupo, porque la contrapartida y las partes del reparto lo
     * siguen— y los aprobados con un pedido de cambio.
     */
    private function esperando(UserRole $user): Builder
    {
        $visibles = $this->scope->cuentasVisibles($user);

        return EjecucionMovimiento::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p
                    ->where('estado_aprobacion', EjecucionMovimiento::PENDIENTE)
                    ->whereNull('distribucion_de_id')
                    ->where(fn ($e) => $e->whereNull('movimiento_espejo_id')
                                         ->orWhereColumn('movimiento_espejo_id', '>', 'id')))
                ->orWhereNotNull('cambio_propuesto'))
            ->when($visibles !== null, fn ($q) => $q->whereIn('cuenta_operativa_id', $visibles ?: [0]));
    }

    /** Busca el movimiento y controla que el usuario pueda decidir sobre él. */
    private function paraDecidir(int $id, UserRole $user): EjecucionMovimiento
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m || !$this->scope->puedeVerCuenta((int) $m->cuenta_operativa_id, $user)) {
            abort(404, 'Movimiento no encontrado.');
        }
        $motivo = $this->motivoParaNoDecidir($m, $user);
        if ($motivo !== null) {
            abort(422, $motivo);
        }
        return $m;
    }

    private function motivoParaNoDecidir(EjecucionMovimiento $m, UserRole $user): ?string
    {
        if (!$user->es_aprobador) {
            return 'No tiene la marca de aprobador.';
        }
        if ($m->cambio_propuesto === null && $m->estado_aprobacion !== EjecucionMovimiento::PENDIENTE) {
            return 'Este movimiento ya no espera aprobación.';
        }
        if ($m->cambio_propuesto === null && $this->movimientos->cabeza($m)->id !== $m->id) {
            return 'Se aprueba desde el movimiento que lo generó.';
        }
        if ($this->solicitante($m) !== null && $this->solicitante($m) === $user->username) {
            return 'Lo pidió usted: lo tiene que aprobar otro aprobador.';
        }
        return null;
    }

    /** Quién pidió lo que espera: el cambio, o la carga. */
    private function solicitante(EjecucionMovimiento $m): ?string
    {
        return $m->cambio_propuesto !== null ? $m->cambio_propuesto_por : $m->cargado_por;
    }

    /**
     * @param  array<string, string>  $nombres
     * @return array<string, mixed>
     */
    private function item(EjecucionMovimiento $m, UserRole $user, array $nombres): array
    {
        $this->movimientos->recortar($m);
        $pedido = $m->cambio_propuesto;
        $tipoPedido = is_array($pedido) ? ($pedido['tipo'] ?? 'modificacion') : 'alta';
        $solicitante = $this->solicitante($m);
        $motivo = $this->motivoParaNoDecidir($m, $user);
        $cuenta = $m->cuenta;

        return array_merge($m->toArray(), [
            'pedido'                => $tipoPedido,
            'reenvio'               => $tipoPedido === 'alta' && $m->aprobacion_observacion !== null,
            'solicitado_por'        => $solicitante,
            'solicitado_por_nombre' => $solicitante !== null ? ($nombres[$solicitante] ?? $solicitante) : null,
            'solicitado_fecha'      => ($tipoPedido === 'alta' ? $m->updated_at : $m->cambio_propuesto_fecha)?->toIso8601String(),
            'cuenta_ruta'           => $cuenta && $cuenta->sector_id !== null
                ? $this->arbol->rutaDe((int) $cuenta->sector_id) : null,
            'puede_decidir'         => $motivo === null,
            'motivo_no_decide'      => $motivo,
            'cambios'               => $tipoPedido === 'modificacion' ? $this->cambios($m, $pedido) : [],
        ]);
    }

    /**
     * Qué cambia el pedido, campo por campo.
     *
     * @param  array<string, mixed>  $pedido
     * @return array<int, array{campo: string, antes: ?string, despues: ?string}>
     */
    private function cambios(EjecucionMovimiento $m, array $pedido): array
    {
        $data  = $pedido['data'] ?? [];
        $nuevo = $this->movimientos->normalizar($data);
        $out   = [];

        foreach (self::CAMPOS as $campo => $etiqueta) {
            if (!array_key_exists($campo, $nuevo)) {
                continue;
            }
            $antes   = $this->valor($campo, $m->getRawOriginal($campo));
            $despues = $this->valor($campo, $nuevo[$campo]);
            if ($antes !== $despues) {
                $out[] = ['campo' => $etiqueta, 'antes' => $antes, 'despues' => $despues];
            }
        }

        if (!empty($pedido['factura'])) {
            $out[] = ['campo' => 'Factura', 'antes' => $m->factura_original_name,
                      'despues' => $pedido['factura']['factura_original_name'] ?? 'Archivo nuevo'];
        } elseif (!empty($pedido['eliminar_factura']) && $m->factura_original_name) {
            $out[] = ['campo' => 'Factura', 'antes' => $m->factura_original_name, 'despues' => null];
        }

        if (!empty($data['distribuir']) || !empty($data['distribucion'])) {
            $antes   = $this->reparto($m->distribuciones->map(fn ($p) => [
                'cuenta_operativa_id' => $p->cuenta_contraparte_id,
                'porcentaje'          => $p->distribucion_porcentaje,
            ])->all());
            $despues = $this->reparto($data['distribucion'] ?? []);
            if ($antes !== $despues) {
                $out[] = ['campo' => 'Reparto', 'antes' => $antes, 'despues' => $despues];
            }
        }

        return $out;
    }

    private function valor(string $campo, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return match ($campo) {
            'monto', 'monto_dolares' => number_format((float) $v, 2, ',', '.'),
            'cotizacion'             => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ','),
            'fecha'                  => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $f)
                ? "{$f[3]}/{$f[2]}/{$f[1]}" : (string) $v,
            'cuenta_contraparte_id'  => $this->nombreCuenta((int) $v),
            'expediente_id'          => Expediente::withTrashed()->find((int) $v)?->nro_expediente ?? "Expediente #{$v}",
            default                  => (string) $v,
        };
    }

    /** @param  array<int, array<string, mixed>>  $partes */
    private function reparto(array $partes): ?string
    {
        if (!$partes) {
            return null;
        }
        return collect($partes)
            ->map(fn ($p) => rtrim(rtrim(number_format((float) $p['porcentaje'], 2, ',', '.'), '0'), ',')
                . ' % a ' . $this->nombreCuenta((int) $p['cuenta_operativa_id']))
            ->implode(' · ');
    }

    private function nombreCuenta(int $id): string
    {
        return $this->scope->puedeVerCuenta($id)
            ? (CuentaOperativa::find($id)?->nombre ?? "Cuenta #{$id}")
            : 'Cuenta fuera de su alcance';
    }

    private function limpiar(?string $texto): ?string
    {
        $texto = $texto !== null ? trim($texto) : null;
        return $texto === '' ? null : $texto;
    }
}
