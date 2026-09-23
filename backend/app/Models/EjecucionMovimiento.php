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
        'moneda',
        'monto',
        'monto_dolares',
        'cotizacion',
        'objeto',
        'factura_path',
        'factura_original_name',
        'factura_mime',
    ];

    protected $casts = [
        'monto'         => 'decimal:2',
        'monto_dolares' => 'decimal:2',
        'cotizacion'    => 'decimal:4',
    ];

    protected $hidden = ['factura_path']; // se entrega vía endpoint, no se expone la ruta

    protected $appends = ['has_factura', 'contraparte'];

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
            'cuenta'    => $this->relationLoaded('cuentaContraparte') && $this->cuentaContraparte
                ? $this->cuentaContraparte->nombre
                : ($this->cuenta_contraparte_id ? "Cuenta #{$this->cuenta_contraparte_id}" : null),
            default     => $this->cliente ?: $this->proveedor ?: $this->rubro,
        };
    }
}
