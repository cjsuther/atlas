<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Máximo nivel de la estructura organizativa. La información de saldos y
 * registros no puede salir de la Gerencia de Área a la que pertenece.
 */
class GerenciaArea extends Model
{
    protected $table      = 'gerencias_area';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = ['sigla', 'nombre', 'responsable', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function gerencias()
    {
        return $this->hasMany(Gerencia::class, 'gerencia_area_id', 'id');
    }

    public function contratos()
    {
        return $this->hasManyThrough(
            ContratoEjecucion::class,
            Gerencia::class,
            'gerencia_area_id',
            'gerencia_id',
            'id',
            'id'
        );
    }
}
