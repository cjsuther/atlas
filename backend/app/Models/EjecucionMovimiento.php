<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Movimiento de ejecución imputado a un contrato.
 *
 * Además de los gastos e ingresos por facturas (solicitud o recepción) existen
 * transferencias a otro contrato —de la misma o de otra gerencia— y pagos de
 * incentivos o MCH (Mayor Carga Horaria). Por eso la contraparte no siempre es
 * un cliente o un proveedor: puede ser otro contrato o simplemente un rubro.
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
    public const CONTRAPARTES = ['cliente', 'proveedor', 'contrato', 'rubro'];

    protected $table      = 'ejecucion_movimientos';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'contrato_ejecucion_id',
        'tipo',
        'accion',
        'nro_expediente',
        'contraparte_tipo',
        'proveedor',
        'cliente',
        'contrato_contraparte_id',
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

    public function contratoEjecucion()
    {
        return $this->belongsTo(ContratoEjecucion::class, 'contrato_ejecucion_id', 'id');
    }

    /** Contrato con el que se hizo la transferencia. */
    public function contratoContraparte()
    {
        return $this->belongsTo(ContratoEjecucion::class, 'contrato_contraparte_id', 'id');
    }

    /** Movimiento generado automáticamente en el contrato contraparte. */
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
            'contrato'  => $this->relationLoaded('contratoContraparte') && $this->contratoContraparte
                ? "#{$this->contratoContraparte->id} — {$this->contratoContraparte->nro_expediente}"
                : ($this->contrato_contraparte_id ? "Contrato #{$this->contrato_contraparte_id}" : null),
            default     => $this->cliente ?: $this->proveedor ?: $this->rubro,
        };
    }
}
