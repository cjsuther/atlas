<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContrato extends Model
{
    protected $table      = 'tipo_de_contrato';
    protected $primaryKey = 'id_tipo';
    public $timestamps    = false;

    protected $fillable = ['tipo', 'nombre'];

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'tipo_de_contrato_id', 'id_tipo');
    }
}
