<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ContratoEjecucion extends Model
{
    use SoftDeletes;

    protected $table      = 'contratos_ejecucion';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'nro_expediente',
        'fecha_apertura_expediente',
        'tipo_contrato_id',
        'nombre_proyecto',
        'descripcion_objeto',
        'contrato_principal_id',
        'gerencia_id',
        'sector_detalle',
        'solicitante_id',
        'resp1_id',
        'resp2_id',
        'utt_id',
        'estado_id',
        'observaciones',
        'uvt_id',
        'cliente',
        'fecha_inicio',
        'fecha_vencimiento',
        'fecha_finalizacion',
        'acta_finalizacion',
        'prorroga',
        'renovacion_automatica',
        'caja_bas',
        'moneda',
        'cotizacion',
        'monto_presupuestado_ingresos',
        'monto_presupuestado_gastos',
    ];

    protected $casts = [
        'fecha_apertura_expediente'    => 'date:Y-m-d',
        'fecha_inicio'                 => 'date:Y-m-d',
        'fecha_vencimiento'            => 'date:Y-m-d',
        'fecha_finalizacion'           => 'date:Y-m-d',
        'prorroga'                     => 'boolean',
        'renovacion_automatica'        => 'boolean',
        'cotizacion'                   => 'decimal:4',
        'monto_presupuestado_ingresos' => 'decimal:2',
        'monto_presupuestado_gastos'   => 'decimal:2',
    ];

    protected $appends = [
        'duracion_meses', 'atraso_meses',
        'monto_ejecutado_ingresos', 'monto_ejecutado_gastos',
    ];

    // ----------------------------------------------------------------------
    // Relaciones
    // ----------------------------------------------------------------------
    public function tipoContrato()
    {
        return $this->belongsTo(TipoContratoEjecucion::class, 'tipo_contrato_id', 'id');
    }

    public function estado()
    {
        return $this->belongsTo(EstadoEjecucion::class, 'estado_id', 'id');
    }

    public function principal()
    {
        return $this->belongsTo(ContratoPrincipal::class, 'contrato_principal_id', 'id');
    }

    public function gerencia()
    {
        return $this->belongsTo(Gerencia::class, 'gerencia_id', 'id');
    }

    /** Atajo a la Gerencia de Área a través de la gerencia del contrato. */
    public function gerenciaArea()
    {
        return $this->hasOneThrough(
            GerenciaArea::class,
            Gerencia::class,
            'id',
            'id',
            'gerencia_id',
            'gerencia_area_id'
        );
    }

    public function solicitante()
    {
        return $this->belongsTo(Solicitante::class, 'solicitante_id', 'solicitante_id');
    }

    public function uvt()
    {
        return $this->belongsTo(Uvt::class, 'uvt_id', 'uvt_id');
    }

    public function utt()
    {
        return $this->belongsTo(Utt::class, 'utt_id', 'utt_id');
    }

    public function resp1()
    {
        return $this->belongsTo(Personal::class, 'resp1_id', 'legajo');
    }

    public function resp2()
    {
        return $this->belongsTo(Personal::class, 'resp2_id', 'legajo');
    }

    public function movimientos()
    {
        return $this->hasMany(EjecucionMovimiento::class, 'contrato_ejecucion_id', 'id');
    }

    // ----------------------------------------------------------------------
    // Campos calculados
    // ----------------------------------------------------------------------
    public function getDuracionMesesAttribute(): ?float
    {
        if (!$this->fecha_inicio || !$this->fecha_vencimiento) return null;
        return round(Carbon::parse($this->fecha_inicio)
            ->floatDiffInMonths(Carbon::parse($this->fecha_vencimiento)), 2);
    }

    public function getAtrasoMesesAttribute(): ?float
    {
        if (!$this->fecha_vencimiento) return null;
        $estadoFinalizado = optional($this->estado)->nombre === 'Finalizado';
        if ($estadoFinalizado) return null;

        $venc = Carbon::parse($this->fecha_vencimiento);
        $hoy  = Carbon::today();
        if ($venc->greaterThanOrEqualTo($hoy)) return null;

        return round($venc->floatDiffInMonths($hoy), 2);
    }

    /**
     * Suma de movimientos tipo "ingreso" (en pesos). Se calcula a partir de:
     * 1) subquery precargado por el Service como `sum_ingresos`
     * 2) o, en su defecto, sumando la relación cargada
     * 3) o, último recurso, una consulta directa.
     */
    public function getMontoEjecutadoIngresosAttribute(): float
    {
        return $this->sumMovimientos('ingreso', 'sum_ingresos');
    }

    public function getMontoEjecutadoGastosAttribute(): float
    {
        return $this->sumMovimientos('gasto', 'sum_gastos');
    }

    private function sumMovimientos(string $tipo, string $aliasPrecargado): float
    {
        if (array_key_exists($aliasPrecargado, $this->attributes)) {
            return round((float) ($this->attributes[$aliasPrecargado] ?? 0), 2);
        }
        if ($this->relationLoaded('movimientos')) {
            return round((float) $this->movimientos->where('tipo', $tipo)->sum('monto'), 2);
        }
        return round((float) $this->movimientos()->where('tipo', $tipo)->sum('monto'), 2);
    }
}
