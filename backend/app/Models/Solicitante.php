<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Solicitante extends Model
{
    protected $table      = 'solicitantes';
    protected $primaryKey = 'solicitante_id';
    public $timestamps    = false;

    protected $fillable = [
        'cuil_cuit',
        'razon_social',
        'rubro',
        'localizacion',
        'telefono',
        'nombre_contacto',
    ];

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'solicitante_id', 'solicitante_id');
    }
}
