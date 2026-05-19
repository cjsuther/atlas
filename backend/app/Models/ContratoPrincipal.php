<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ContratoPrincipal extends Model
{
    use SoftDeletes;

    protected $table      = 'contratos_principal';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'nro_expediente',
        'fecha_apertura_expediente',
        'regimen',
        'tipo_contrato_id',
        'nombre_proyecto',
        'descripcion_objeto',
        'gerencia_area',
        'gerencia',
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
    ];

    protected $casts = [
        'fecha_apertura_expediente' => 'date:Y-m-d',
        'fecha_inicio'              => 'date:Y-m-d',
        'fecha_vencimiento'         => 'date:Y-m-d',
        'fecha_finalizacion'        => 'date:Y-m-d',
        'prorroga'                  => 'boolean',
        'renovacion_automatica'     => 'boolean',
        'cotizacion'                => 'decimal:4',
    ];

    protected $appends = [
        'duracion_meses', 'atraso_meses',
        'monto_ejecutado_ingresos', 'monto_ejecutado_gastos', 'monto_beneficio',
    ];

    // ----------------------------------------------------------------------
    // Relaciones
    // ----------------------------------------------------------------------
    public function tipoContrato()
    {
        return $this->belongsTo(TipoContratoPrincipal::class, 'tipo_contrato_id', 'id');
    }

    public function estado()
    {
        return $this->belongsTo(EstadoPrincipal::class, 'estado_id', 'id');
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

    public function ejecuciones()
    {
        return $this->hasMany(ContratoEjecucion::class, 'contrato_principal_id', 'id');
    }

    // ----------------------------------------------------------------------
    // Campos calculados
    // ----------------------------------------------------------------------

    /** Diferencia en meses entre fecha_inicio y fecha_vencimiento. */
    public function getDuracionMesesAttribute(): ?float
    {
        if (!$this->fecha_inicio || !$this->fecha_vencimiento) return null;
        return round(Carbon::parse($this->fecha_inicio)
            ->floatDiffInMonths(Carbon::parse($this->fecha_vencimiento)), 2);
    }

    /** Meses de atraso si está vencido y no finalizado. */
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
     * Monto ejecutado de ingresos = suma de monto_ejecutado_ingresos
     * de los contratos de ejecución vinculados (excluye bajas lógicas).
     * Si el Service trae las ejecuciones precargadas, las usa; si no,
     * hace la consulta puntual.
     */
    public function getMontoEjecutadoIngresosAttribute(): float
    {
        return $this->sumEjecuciones('monto_ejecutado_ingresos');
    }

    public function getMontoEjecutadoGastosAttribute(): float
    {
        return $this->sumEjecuciones('monto_ejecutado_gastos');
    }

    /** Beneficio = ejecutado ingresos − ejecutado gastos. */
    public function getMontoBeneficioAttribute(): float
    {
        return round($this->monto_ejecutado_ingresos - $this->monto_ejecutado_gastos, 2);
    }

    private function sumEjecuciones(string $campo): float
    {
        // Mapeo: campo del accessor → tipo de movimiento + alias precargado por Service
        $map = [
            'monto_ejecutado_ingresos' => ['tipo' => 'ingreso', 'alias' => 'sum_ejec_ingresos'],
            'monto_ejecutado_gastos'   => ['tipo' => 'gasto',   'alias' => 'sum_ejec_gastos'],
        ];
        $tipo  = $map[$campo]['tipo']  ?? null;
        $alias = $map[$campo]['alias'] ?? null;
        if (!$tipo) return 0.0;

        // 1) Alias precargado por el Service (subquery SQL)
        if ($alias && array_key_exists($alias, $this->attributes)) {
            return round((float) ($this->attributes[$alias] ?? 0), 2);
        }
        // 2) Si la relación ejecuciones.movimientos fue cargada, sumar en memoria
        if ($this->relationLoaded('ejecuciones')) {
            $total = 0.0;
            foreach ($this->ejecuciones as $e) {
                if ($e->relationLoaded('movimientos')) {
                    $total += (float) $e->movimientos->where('tipo', $tipo)->sum('monto');
                } else {
                    $total += (float) $e->movimientos()->where('tipo', $tipo)->sum('monto');
                }
            }
            return round($total, 2);
        }
        // 3) Último recurso: una query SQL agregada para este principal.
        return round(
            (float) \DB::table('ejecucion_movimientos as m')
                ->join('contratos_ejecucion as ce', 'ce.id', '=', 'm.contrato_ejecucion_id')
                ->where('ce.contrato_principal_id', $this->id)
                ->where('m.tipo', $tipo)
                ->whereNull('m.deleted_at')
                ->whereNull('ce.deleted_at')
                ->sum('m.monto'),
            2
        );
    }
}
