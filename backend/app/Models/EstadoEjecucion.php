<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoEjecucion extends Model
{
    protected $table      = 'estado_ejecucion';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = ['nombre', 'descripcion'];

    public function contratos()
    {
        return $this->hasMany(ContratoEjecucion::class, 'estado_id', 'id');
    }
}
