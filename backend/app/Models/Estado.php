<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estado extends Model
{
    protected $table      = 'estado';
    protected $primaryKey = 'estado_id';
    public $timestamps    = false;

    protected $fillable = ['estado_nombre', 'descripcion'];

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'estado_id', 'estado_id');
    }
}
