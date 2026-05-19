<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoContratoPrincipal extends Model
{
    protected $table      = 'tipo_contrato_principal';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = ['sigla', 'nombre'];

    public function contratos()
    {
        return $this->hasMany(ContratoPrincipal::class, 'tipo_contrato_id', 'id');
    }
}
