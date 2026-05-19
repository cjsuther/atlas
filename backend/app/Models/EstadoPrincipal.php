<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoPrincipal extends Model
{
    protected $table      = 'estado_principal';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = ['nombre'];

    public function contratos()
    {
        return $this->hasMany(ContratoPrincipal::class, 'estado_id', 'id');
    }
}
