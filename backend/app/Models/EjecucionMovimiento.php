<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EjecucionMovimiento extends Model
{
    use SoftDeletes;

    protected $table      = 'ejecucion_movimientos';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'contrato_ejecucion_id',
        'tipo',
        'nro_expediente',
        'proveedor',
        'cliente',
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

    protected $appends = ['has_factura'];

    public function contratoEjecucion()
    {
        return $this->belongsTo(ContratoEjecucion::class, 'contrato_ejecucion_id', 'id');
    }

    public function getHasFacturaAttribute(): bool
    {
        return !empty($this->attributes['factura_path']);
    }
}
