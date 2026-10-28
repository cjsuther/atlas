<?php

namespace App\Services;

use App\Models\EjecucionMovimiento;
use App\Models\Expediente;
use App\Models\LogActividad;
use App\Support\RegistroActividad;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Alta, modificación y baja de los movimientos de una cuenta.
 *
 * Nada de lo que se carga mueve el saldo hasta que lo aprueba un aprobador
 * (ver AprobacionService):
 *  - lo nuevo entra «Sin aprobar»;
 *  - lo que todavía no se aprobó, o se rechazó, se corrige directamente y
 *    vuelve a quedar sin aprobar;
 *  - lo aprobado no se toca: modificarlo o darlo de baja deja el pedido en
 *    `cambio_propuesto`, y el saldo sigue con los valores aprobados hasta que
 *    se decida.
 * La contrapartida de una transferencia y las partes de un reparto siguen el
 * estado del movimiento que las generó.
 */
class EjecucionMovimientoService
{
    /** Lo que se carga junto al movimiento para mostrarlo en pantalla. */
    public const RELACIONES = [
        'cuenta:id,nombre,sector_id',
        'cuentaContraparte:id,nombre,sector_id',
        'expediente:id,nro_expediente,sector_id',
        'distribuciones:id,distribucion_de_id,cuenta_contraparte_id,distribucion_porcentaje,monto,monto_dolares,tipo',
        'distribuciones.cuentaContraparte:id,nombre',
    ];

    /** Disk del filesystem donde se persisten las facturas. */
    public const FACTURA_DISK = 'local';
    public const FACTURA_DIR  = 'facturas';

    /** Campos que se replican en la contrapartida de una transferencia. */
    private const CAMPOS_ESPEJO = [
        'nro_expediente', 'moneda', 'monto', 'monto_dolares', 'cotizacion', 'objeto',
        'expediente_id', 'fecha',
    ];

    /** Evita que la sincronización de la contrapartida se dispare a sí misma. */
    private bool $sincronizando = false;

    public function __construct(protected AccessScopeService $scope)
    {
    }

    /** Historial de una cuenta: todo lo que se registró contra ella. */
    public function listForCuenta(int $cuentaOperativaId, array $filters): LengthAwarePaginator
    {
        return $this->listar(
            EjecucionMovimiento::query()->where('cuenta_operativa_id', $cuentaOperativaId),
            $filters,
        );
    }

    /**
     * Movimientos que declaran a este contrato como contrato relacionado. Se
     * registran en cuentas de cualquier rama, así que se recortan a las que el
     * usuario puede ver.
     */
    public function listForContrato(int $expedienteId, array $filters): LengthAwarePaginator
    {
        return $this->listar(
            $this->scope->aplicarAMovimientos(
                EjecucionMovimiento::query()->where('expediente_id', $expedienteId)
            ),
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

        $pagina = $q->orderBy('fecha', 'desc')->orderBy('id', 'desc')->paginate($perPage);
        $pagina->getCollection()->each(fn ($m) => $this->recortar($m));
        return $pagina;
    }

    public function find(int $id, bool $withTrashed = false): ?EjecucionMovimiento
    {
        $q = EjecucionMovimiento::query()->with(self::RELACIONES);
        if ($withTrashed) $q->withTrashed();
        $m = $q->find($id);
        return $m ? $this->recortar($m) : null;
    }

    /**
     * Prepara el movimiento para mostrarlo: no nombra la cuenta contraparte ni
     * el expediente si están fuera del alcance del usuario, y dice si puede
     * modificarlo.
     */
    public function recortar(EjecucionMovimiento $m): EjecucionMovimiento
    {
        if ($m->cuenta_contraparte_id !== null
            && !$this->scope->puedeVerCuenta((int) $m->cuenta_contraparte_id)) {
            $m->setRelation('cuentaContraparte', null);
        }

        $expediente = $m->relationLoaded('expediente') ? $m->expediente : null;
        if ($expediente && !$this->scope->puedeVerContrato($expediente)) {
            $m->setRelation('expediente', null);
            $m->nro_expediente = null;
        }

        // Las partes del reparto se nombran sólo si el usuario ve esas cuentas.
        $reparto = $m->relationLoaded('distribuciones') ? $m->distribuciones : collect();
        foreach ($reparto as $parte) {
            if (!$this->scope->puedeVerCuenta((int) $parte->cuenta_contraparte_id)) {
                $parte->setRelation('cuentaContraparte', null);
            }
        }

        // Una parte del reparto se cambia desde el ingreso que la generó, y el
        // ingreso, sólo con escritura sobre todas las cuentas a las que reparte.
        $editable = $m->distribucion_de_id === null
            && $this->scope->puedeEditarMovimiento($m)
            && $reparto->every(fn ($p) => $this->scope->puedeUsarCuenta((int) $p->cuenta_contraparte_id));
        $m->setAttribute('editable', $editable);
        $m->setAttribute('generado_por_reparto', $m->distribucion_de_id !== null);
        return $m;
    }

    private function usuarioActual(): ?string
    {
        return Auth::user()?->username;
    }

    public function create(int $cuentaOperativaId, array $data, ?UploadedFile $factura = null): EjecucionMovimiento
    {
        $reparto = $this->repartoPedido($data);
        $data = $this->conNumeroDeExpediente($data);
        $data = $this->normalizeMontos($data);
        $data = $this->normalizeContraparte($data);
        $data['cuenta_operativa_id'] = $cuentaOperativaId;
        $data['estado_aprobacion']   = EjecucionMovimiento::PENDIENTE;
        $data['cargado_por']         = $this->usuarioActual();
        unset($data['factura'], $data['eliminar_factura']);

        if ($factura && $this->admiteFactura($data)) {
            $data = array_merge($data, $this->storeFactura($factura));
        }

        return DB::transaction(function () use ($data, $reparto) {
            $m = new EjecucionMovimiento();
            $m->fill($data);
            $m->save();

            if ($this->esTransferencia($m)) {
                $this->crearEspejo($m);
            }
            if ($reparto) {
                $this->repartir($m, $reparto);
            }

            RegistroActividad::movimiento(LogActividad::ALTA, $m);
            return $m->fresh();
        });
    }

    /**
     * Modifica un movimiento. Si ya está aprobado, el cambio queda pedido y
     * se aplica al aprobarse; si no, se aplica y vuelve a quedar sin aprobar.
     */
    public function update(int $id, array $data, ?UploadedFile $factura = null, bool $eliminarFactura = false): ?EjecucionMovimiento
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return null;

        unset($data['factura'], $data['eliminar_factura']);
        $archivo = $factura ? $this->storeFactura($factura) : null;

        if ($m->estaAprobado()) {
            return $this->pedirCambio($m, [
                'tipo'             => 'modificacion',
                'data'             => $data,
                'factura'          => $archivo,
                'eliminar_factura' => $eliminarFactura,
            ]);
        }

        $estabaRechazado = $m->estado_aprobacion === EjecucionMovimiento::RECHAZADO;

        return DB::transaction(function () use ($m, $data, $archivo, $eliminarFactura, $estabaRechazado) {
            $m = $this->aplicar($m, $data, $archivo, $eliminarFactura);
            $this->marcarGrupo($m, EjecucionMovimiento::PENDIENTE);
            RegistroActividad::movimiento(
                $estabaRechazado ? LogActividad::REENVIO : LogActividad::MODIFICACION,
                $m,
            );
            return $m->fresh();
        });
    }

    /**
     * Graba los datos en el movimiento y rehace lo que depende de él: la
     * contrapartida y el reparto. Es la modificación en sí, sin pasar por la
     * aprobación; la usa también el aprobador al aceptar un pedido.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>|null  $archivo  factura nueva ya guardada
     */
    public function aplicar(EjecucionMovimiento $m, array $data, ?array $archivo = null, bool $eliminarFactura = false): EjecucionMovimiento
    {
        $reparto = $this->repartoPedido($data);
        $data = $this->normalizar($data);

        if ($archivo) {
            // Reemplazo de factura: borra la vieja si había
            $this->borrarFactura($m);
            $data = array_merge($data, $archivo);
        } elseif ($eliminarFactura) {
            $this->borrarFactura($m);
            $data['factura_path']          = null;
            $data['factura_original_name'] = null;
            $data['factura_mime']          = null;
        }

        return DB::transaction(function () use ($m, $data, $reparto) {
            $m->fill($data);
            $m->save();

            $this->sincronizarEspejo($m);

            // Sin un reparto nuevo, el que tenía se recalcula sobre el monto
            // actual con los mismos porcentajes.
            $reparto ??= $m->distribuciones()->get()
                ->map(fn ($p) => [
                    'cuenta_operativa_id' => (int) $p->cuenta_contraparte_id,
                    'porcentaje'          => (float) $p->distribucion_porcentaje,
                ])->all();
            $this->quitarReparto($m);
            if ($reparto && $this->admiteReparto($m)) {
                $this->repartir($m, $reparto);
            }

            return $m->fresh();
        });
    }

    /**
     * Da de baja un movimiento. Si está aprobado, queda pedida la baja y el
     * movimiento sigue sumando hasta que se apruebe.
     *
     * @return 'baja'|'pedido'|null  null si no existe
     */
    public function softDelete(int $id): ?string
    {
        $m = EjecucionMovimiento::find($id);
        if (!$m) return null;

        if ($m->estaAprobado()) {
            $this->pedirCambio($m, ['tipo' => 'baja']);
            return 'pedido';
        }

        DB::transaction(function () use ($m) {
            RegistroActividad::movimiento(LogActividad::BAJA, $m);
            $this->darDeBaja($m);
        });
        return 'baja';
    }

    /** La baja en sí: el movimiento, su contrapartida y lo que se repartió de él. */
    public function darDeBaja(EjecucionMovimiento $m): bool
    {
        return (bool) DB::transaction(function () use ($m) {
            $espejo = $m->movimiento_espejo_id
                ? EjecucionMovimiento::find($m->movimiento_espejo_id)
                : null;

            // Sin el ingreso, lo que se repartió de él tampoco tiene sentido.
            $this->quitarReparto($m);

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
    // Aprobación
    // ------------------------------------------------------------------

    /**
     * Deja pedido un cambio sobre un movimiento aprobado. Un pedido nuevo
     * reemplaza al que estuviera esperando.
     *
     * @param  array<string, mixed>  $pedido
     */
    private function pedirCambio(EjecucionMovimiento $m, array $pedido): EjecucionMovimiento
    {
        $this->descartarPedido($m);

        $m->cambio_propuesto       = $pedido;
        $m->cambio_propuesto_por   = $this->usuarioActual();
        $m->cambio_propuesto_fecha = now();
        $m->save();

        RegistroActividad::movimiento(
            $pedido['tipo'] === 'baja' ? LogActividad::PEDIDO_BAJA : LogActividad::PEDIDO_MODIFICACION,
            $m,
        );
        return $m->fresh();
    }

    /** Borra el pedido que estaba esperando, con la factura que traía. */
    public function descartarPedido(EjecucionMovimiento $m): void
    {
        $path = $m->cambio_propuesto['factura']['factura_path'] ?? null;
        if ($path) {
            Storage::disk(self::FACTURA_DISK)->delete($path);
        }
        $m->cambio_propuesto       = null;
        $m->cambio_propuesto_por   = null;
        $m->cambio_propuesto_fecha = null;
    }

    /**
     * El movimiento que manda en la aprobación: el original de una
     * transferencia —la contrapartida se crea después, así que tiene un id
     * mayor— o el ingreso del que salió una parte del reparto.
     */
    public function cabeza(EjecucionMovimiento $m): EjecucionMovimiento
    {
        if ($m->distribucion_de_id !== null) {
            $ingreso = EjecucionMovimiento::withTrashed()->find($m->distribucion_de_id);
            if ($ingreso) {
                return $ingreso;
            }
        }
        if ($m->movimiento_espejo_id !== null && (int) $m->movimiento_espejo_id < (int) $m->id) {
            $original = EjecucionMovimiento::withTrashed()->find($m->movimiento_espejo_id);
            if ($original) {
                return $this->cabeza($original);
            }
        }
        return $m;
    }

    /**
     * Pone a todo el grupo —el movimiento, su contrapartida y las partes del
     * reparto con las suyas— en el mismo estado de aprobación.
     *
     * @param  array<string, mixed>  $extra  campos de la decisión, para el movimiento principal
     */
    public function marcarGrupo(EjecucionMovimiento $m, string $estado, array $extra = []): void
    {
        $cabeza = $this->cabeza($m);

        $ids = EjecucionMovimiento::where('distribucion_de_id', $cabeza->id)->pluck('id')->all();
        if ($cabeza->movimiento_espejo_id) {
            $ids[] = (int) $cabeza->movimiento_espejo_id;
        }
        if ($ids) {
            // Sin pasar por el modelo: el historial registra el cambio una sola
            // vez, en el movimiento principal.
            EjecucionMovimiento::whereIn('id', $ids)->update(['estado_aprobacion' => $estado]);
        }

        $cabeza->forceFill($extra);
        $cabeza->estado_aprobacion = $estado;
        $cabeza->save();
    }

    /**
     * Los datos como quedarían grabados, sin grabarlos: lo que la bandeja del
     * aprobador compara con lo actual.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizar(array $data): array
    {
        unset($data['distribuir'], $data['distribucion']);
        $data = $this->conNumeroDeExpediente($data);
        $data = $this->normalizeMontos($data);
        return $this->normalizeContraparte($data);
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
                'fecha'                   => $origen->fecha,
                'distribucion_de_id'      => $origen->distribucion_de_id,
                'distribucion_porcentaje' => $origen->distribucion_porcentaje,
                'estado_aprobacion'       => $origen->estado_aprobacion,
                'cargado_por'             => $origen->cargado_por,
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
    // Reparto de un ingreso entre cuentas
    // ------------------------------------------------------------------

    /**
     * El reparto que trae el pedido, o null si no trae ninguno. Se saca de
     * los datos porque no son columnas del movimiento.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{cuenta_operativa_id: int, porcentaje: float}>|null
     */
    private function repartoPedido(array &$data): ?array
    {
        $pedido = !empty($data['distribuir']) || !empty($data['distribucion']);
        $partes = $data['distribucion'] ?? [];
        unset($data['distribuir'], $data['distribucion']);

        if (!$pedido) {
            return null;
        }
        return array_values(array_map(fn ($p) => [
            'cuenta_operativa_id' => (int) $p['cuenta_operativa_id'],
            'porcentaje'          => (float) $p['porcentaje'],
        ], $partes ?: []));
    }

    private function admiteReparto(EjecucionMovimiento $m): bool
    {
        return $m->accion === EjecucionMovimiento::ACCION_FACTURA && $m->tipo === 'ingreso';
    }

    /**
     * Reparte el ingreso: por cada parte sale de su cuenta una transferencia
     * por ese porcentaje, con su contrapartida en la cuenta de destino. Lo que
     * no se reparte queda en la cuenta del ingreso.
     *
     * Si el reparto suma 100 %, la última parte se lleva lo que dejó el
     * redondeo, para que no quede un centavo suelto en la cuenta.
     *
     * @param  array<int, array{cuenta_operativa_id: int, porcentaje: float}>  $partes
     */
    private function repartir(EjecucionMovimiento $ingreso, array $partes): void
    {
        $total    = (float) $ingreso->monto;
        $totalUsd = $ingreso->moneda === 'Dólar' ? (float) $ingreso->monto_dolares : null;
        $completo = abs(array_sum(array_column($partes, 'porcentaje')) - 100) < 0.0001;

        $acumulado = 0.0;
        $acumuladoUsd = 0.0;
        foreach (array_values($partes) as $i => $parte) {
            $ultima = $completo && $i === count($partes) - 1;
            $p = $parte['porcentaje'];

            $monto = $ultima ? round($total - $acumulado, 2) : round($total * $p / 100, 2);
            $acumulado += $monto;
            $montoUsd = null;
            if ($totalUsd !== null) {
                $montoUsd = $ultima ? round($totalUsd - $acumuladoUsd, 2) : round($totalUsd * $p / 100, 2);
                $acumuladoUsd += $montoUsd;
            }

            $t = new EjecucionMovimiento();
            $t->fill([
                'cuenta_operativa_id'     => $ingreso->cuenta_operativa_id,
                'expediente_id'           => $ingreso->expediente_id,
                'nro_expediente'          => $ingreso->nro_expediente,
                'tipo'                    => 'gasto',
                'accion'                  => EjecucionMovimiento::ACCION_TRANSFERENCIA,
                'contraparte_tipo'        => 'cuenta',
                'cuenta_contraparte_id'   => $parte['cuenta_operativa_id'],
                'moneda'                  => $ingreso->moneda,
                'monto'                   => $monto,
                'monto_dolares'           => $montoUsd,
                'cotizacion'              => $ingreso->cotizacion,
                'objeto'                  => 'Reparto del ' . $this->porcentaje($p) . ' del ingreso #'
                                             . $ingreso->id . ': ' . $ingreso->objeto,
                'fecha'                   => $ingreso->fecha,
                'distribucion_de_id'      => $ingreso->id,
                'distribucion_porcentaje' => $p,
                'estado_aprobacion'       => $ingreso->estado_aprobacion,
                'cargado_por'             => $ingreso->cargado_por,
            ]);
            $t->save();
            $this->crearEspejo($t);
        }
    }

    /** Da de baja las transferencias de un reparto, con sus contrapartidas. */
    private function quitarReparto(EjecucionMovimiento $ingreso): void
    {
        $this->sincronizando = true;
        try {
            EjecucionMovimiento::where('distribucion_de_id', $ingreso->id)
                ->get()
                ->each(fn ($t) => $t->delete());
        } finally {
            $this->sincronizando = false;
        }
    }

    private function porcentaje(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',') . ' %';
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
