<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContratoEjecucion extends Model
{
    protected $table      = 'tipo_contrato_ejecucion';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = ['sigla', 'nombre'];

    public function contratos()
    {
        return $this->hasMany(ContratoEjecucion::class, 'tipo_contrato_id', 'id');
    }
}
