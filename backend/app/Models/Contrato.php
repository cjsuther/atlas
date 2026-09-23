<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ficha del Contrato, el tercer nivel de la estructura.
 *
 * Es 1:1 con su nodo del árbol: la clave es el `sector_id`. Guarda lo que antes
 * llevaba cada expediente —tipo, estado, UVT, solicitante, responsables,
 * fechas— y el monto del contrato en su moneda.
 *
 * El monto puede estar en pesos, dólares o euros. Si no es pesos se exige la
 * cotización, que es la que lo lleva a pesos para poder sumarlo con el resto.
 */
class Contrato extends Model
{
    public const MONEDAS = ['Peso', 'Dólar', 'Euro'];

    protected $table      = 'contratos';
    protected $primaryKey = 'sector_id';
    public $incrementing  = false;

    protected $fillable = [
        'sector_id',
        'tipo_contrato_id',
        'estado_id',
        'uvt_id',
        'solicitante_id',
        'resp1_id',
        'resp2_id',
        'descripcion_objeto',
        'cliente',
        'caja_bas',
        'fecha_inicio',
        'fecha_vencimiento',
        'fecha_finalizacion',
        'acta_finalizacion',
        'prorroga',
        'renovacion_automatica',
        'monto',
        'moneda',
        'cotizacion',
        'observaciones',
    ];

    protected $casts = [
        'fecha_inicio'          => 'date:Y-m-d',
        'fecha_vencimiento'     => 'date:Y-m-d',
        'fecha_finalizacion'    => 'date:Y-m-d',
        'prorroga'              => 'boolean',
        'renovacion_automatica' => 'boolean',
        'monto'                 => 'decimal:2',
        'cotizacion'            => 'decimal:4',
    ];

    protected $appends = ['monto_pesos'];

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoContratoEjecucion::class, 'tipo_contrato_id');
    }

    public function estado()
    {
        return $this->belongsTo(EstadoEjecucion::class, 'estado_id');
    }

    public function uvt()
    {
        return $this->belongsTo(Uvt::class, 'uvt_id', 'uvt_id');
    }

    public function solicitante()
    {
        return $this->belongsTo(Solicitante::class, 'solicitante_id', 'solicitante_id');
    }

    public function responsable1()
    {
        return $this->belongsTo(Personal::class, 'resp1_id', 'legajo');
    }

    public function responsable2()
    {
        return $this->belongsTo(Personal::class, 'resp2_id', 'legajo');
    }

    /**
     * El monto llevado a pesos: en pesos va tal cual, en otra moneda se
     * multiplica por la cotización. Sin monto, o sin cotización cuando hace
     * falta, no hay equivalente.
     */
    public function getMontoPesosAttribute(): ?float
    {
        if ($this->monto === null) {
            return null;
        }
        if ($this->moneda === 'Peso') {
            return round((float) $this->monto, 2);
        }
        if ($this->cotizacion === null) {
            return null;
        }
        return round((float) $this->monto * (float) $this->cotizacion, 2);
    }
}
