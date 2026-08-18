<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Segundo nivel de la estructura: cada gerencia pertenece a una Gerencia de
 * Área y cada contrato pertenece a una gerencia.
 */
class Gerencia extends Model
{
    protected $table      = 'gerencias';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = ['gerencia_area_id', 'sigla', 'nombre', 'responsable', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function gerenciaArea()
    {
        return $this->belongsTo(GerenciaArea::class, 'gerencia_area_id', 'id');
    }

    public function contratos()
    {
        return $this->hasMany(ContratoEjecucion::class, 'gerencia_id', 'id');
    }

    public function usuarios()
    {
        return $this->hasMany(UserRole::class, 'gerencia_id', 'id');
    }
}
