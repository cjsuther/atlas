<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrato extends Model
{
    protected $table      = 'contratos';
    protected $primaryKey = 'id_cto';
    public $timestamps    = true;

    protected $fillable = [
        'nombre_proy',
        'dependencia_contractual_id',
        'operatoria_id',
        'fecha_expediente',
        'estado_id',
        'expediente',
        'solicitud_sector_gde',
        'descripcion_objeto',
        'tipo_de_contrato_id',
        'observaciones',
        'solicitante_id',
        'uvt_id',
        'sector_id',
        'gerencia',
        'gerencia_area',
        'fecha_firma',
        'fecha_inicio',
        'fecha_vencimiento',
        'fecha_finalizado',
        'duracion_meses',
        'atraso_meses',
        'prorroga',
        'renovacion_automatica',
        'acta_finalizacion',
        'resp1_id',
        'resp2_id',
        'caja_bas',
        'resp_caja',
        'monto_pesos',
        'monto_usd',
        'monto_euros',
        'monto_otro',
        'moneda_otro',
        'automatico_ejecucion',
        'automatico_finalizado',
    ];

    protected $casts = [
        'fecha_expediente'      => 'date:Y-m-d',
        'fecha_firma'           => 'date:Y-m-d',
        'fecha_inicio'          => 'date:Y-m-d',
        'fecha_vencimiento'     => 'date:Y-m-d',
        'fecha_finalizado'      => 'date:Y-m-d',
        'prorroga'              => 'boolean',
        'renovacion_automatica' => 'boolean',
        'automatico_ejecucion'  => 'boolean',
        'automatico_finalizado' => 'boolean',
        'monto_pesos'           => 'decimal:2',
        'monto_usd'             => 'decimal:2',
        'monto_euros'           => 'decimal:2',
        'monto_otro'            => 'decimal:2',
        'duracion_meses'        => 'integer',
        'atraso_meses'          => 'integer',
    ];

    // ----------------------------------------------------------------------
    // Relaciones
    // ----------------------------------------------------------------------
    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id', 'estado_id');
    }

    public function tipoContrato()
    {
        return $this->belongsTo(TipoContrato::class, 'tipo_de_contrato_id', 'id_tipo');
    }

    public function solicitante()
    {
        return $this->belongsTo(Solicitante::class, 'solicitante_id', 'solicitante_id');
    }

    public function uvt()
    {
        return $this->belongsTo(Uvt::class, 'uvt_id', 'uvt_id');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    public function resp1()
    {
        return $this->belongsTo(Personal::class, 'resp1_id', 'legajo');
    }

    public function resp2()
    {
        return $this->belongsTo(Personal::class, 'resp2_id', 'legajo');
    }

    public function dependenciaContractual()
    {
        return $this->belongsTo(Contrato::class, 'dependencia_contractual_id', 'id_cto');
    }

    public function adendas()
    {
        return $this->hasMany(Contrato::class, 'dependencia_contractual_id', 'id_cto');
    }
}
