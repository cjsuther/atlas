<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Movimiento registrado en una cuenta operativa.
 *
 * Además de los gastos e ingresos por facturas (solicitud o recepción) existen
 * transferencias a otra cuenta —de la misma o de otra gerencia— y pagos de
 * incentivos o MCH (Mayor Carga Horaria). Por eso la contraparte no siempre es
 * un cliente o un proveedor: puede ser otra cuenta o simplemente un rubro.
 *
 * El contrato es opcional: indica con qué convenio se relaciona el movimiento,
 * porque una cuenta puede acumular ingresos de varios y sus gastos no siempre
 * se vinculan a uno.
 */
class EjecucionMovimiento extends Model
{
    use SoftDeletes;

    /** Acción que originó el movimiento. */
    public const ACCION_FACTURA       = 'factura';
    public const ACCION_TRANSFERENCIA = 'transferencia';
    public const ACCION_INCENTIVO     = 'incentivo';
    public const ACCION_MCH           = 'mch';

    public const ACCIONES = [
        self::ACCION_FACTURA,
        self::ACCION_TRANSFERENCIA,
        self::ACCION_INCENTIVO,
        self::ACCION_MCH,
    ];

    /**
     * Aprobación. Lo que se carga queda pendiente —«Sin aprobar»— y no mueve
     * el saldo hasta que un aprobador con alcance sobre la cuenta lo aprueba.
     * Lo rechazado tampoco suma: vuelve a quien lo cargó para corregirlo.
     */
    public const PENDIENTE = 'pendiente';
    public const APROBADO  = 'aprobado';
    public const RECHAZADO = 'rechazado';

    public const ESTADOS_APROBACION = [
        self::PENDIENTE => 'Sin aprobar',
        self::APROBADO  => 'Aprobado',
        self::RECHAZADO => 'Rechazado',
    ];

    /** Campo de contraparte que aplica según la acción. */
    public const CONTRAPARTES = ['cliente', 'proveedor', 'cuenta', 'rubro'];

    protected $table      = 'ejecucion_movimientos';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'cuenta_operativa_id',
        'expediente_id',
        'tipo',
        'accion',
        'nro_expediente',
        'contraparte_tipo',
        'proveedor',
        'cliente',
        'expediente_contraparte_id',
        'cuenta_contraparte_id',
        'rubro',
        'movimiento_espejo_id',
        'distribucion_de_id',
        'distribucion_porcentaje',
        'estado_aprobacion',
        'cargado_por',
        'moneda',
        'monto',
        'monto_dolares',
        'cotizacion',
        'objeto',
        'fecha',
        'factura_path',
        'factura_original_name',
        'factura_mime',
    ];

    protected $casts = [
        'monto'         => 'decimal:2',
        'monto_dolares' => 'decimal:2',
        'cotizacion'    => 'decimal:4',
        'distribucion_porcentaje' => 'decimal:2',
        'fecha'         => 'date:Y-m-d',
        'aprobacion_fecha'       => 'datetime',
        'cambio_propuesto'       => 'array',
        'cambio_propuesto_fecha' => 'datetime',
    ];

    // La factura se entrega vía endpoint, no se expone la ruta. El cambio
    // propuesto se muestra traducido desde la bandeja del aprobador.
    protected $hidden = ['factura_path', 'cambio_propuesto'];

    protected $appends = ['has_factura', 'contraparte', 'estado_aprobacion_label', 'cambio_pendiente'];

    /** Lo que suma en los saldos. */
    public function scopeAprobados($q)
    {
        return $q->where($this->qualifyColumn('estado_aprobacion'), self::APROBADO);
    }

    public function estaAprobado(): bool
    {
        return $this->estado_aprobacion === self::APROBADO;
    }

    public function getEstadoAprobacionLabelAttribute(): ?string
    {
        return self::ESTADOS_APROBACION[$this->estado_aprobacion] ?? null;
    }

    /** Si hay un pedido de modificación o de baja esperando: 'modificacion', 'baja' o null. */
    public function getCambioPendienteAttribute(): ?string
    {
        $c = $this->cambio_propuesto;
        return is_array($c) ? ($c['tipo'] ?? 'modificacion') : null;
    }

    public function expediente()
    {
        return $this->belongsTo(Expediente::class, 'expediente_id', 'id');
    }

    /** Cuenta en la que se registra el movimiento. */
    public function cuenta()
    {
        return $this->belongsTo(CuentaOperativa::class, 'cuenta_operativa_id', 'id');
    }

    /** Cuenta con la que se hizo la transferencia. */
    public function cuentaContraparte()
    {
        return $this->belongsTo(CuentaOperativa::class, 'cuenta_contraparte_id', 'id');
    }

    /** Contrato con el que se hizo la transferencia (histórico). */
    public function expedienteContraparte()
    {
        return $this->belongsTo(Expediente::class, 'expediente_contraparte_id', 'id');
    }

    /**
     * Partes en que se repartió un ingreso: las transferencias que salen de
     * su cuenta. Sus contrapartidas también apuntan al ingreso, pero son el
     * otro lado de la misma parte.
     */
    public function distribuciones()
    {
        return $this->hasMany(self::class, 'distribucion_de_id', 'id')->where('tipo', 'gasto');
    }

    /** Ingreso que generó esta transferencia al repartirse. */
    public function distribucionDe()
    {
        return $this->belongsTo(self::class, 'distribucion_de_id', 'id');
    }

    /** Movimiento generado automáticamente en la cuenta contraparte. */
    public function espejo()
    {
        return $this->belongsTo(self::class, 'movimiento_espejo_id', 'id');
    }

    public function getHasFacturaAttribute(): bool
    {
        return !empty($this->attributes['factura_path']);
    }

    /** Texto de la contraparte, cualquiera sea su tipo, para listados y export. */
    public function getContraparteAttribute(): ?string
    {
        return match ($this->contraparte_tipo) {
            'cliente'   => $this->cliente,
            'proveedor' => $this->proveedor,
            'rubro'     => $this->rubro,
            // Cargada la relación, si viene vacía es una cuenta que el usuario
            // no puede ver: no se la nombra.
            'cuenta'    => match (true) {
                !$this->cuenta_contraparte_id              => null,
                !$this->relationLoaded('cuentaContraparte') => "Cuenta #{$this->cuenta_contraparte_id}",
                $this->cuentaContraparte !== null          => $this->cuentaContraparte->nombre,
                default                                    => 'Cuenta fuera de su alcance',
            },
            default     => $this->cliente ?: $this->proveedor ?: $this->rubro,
        };
    }
}
