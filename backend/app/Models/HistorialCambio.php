<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialCambio extends Model
{
    protected $table      = 'historial_cambios';
    protected $primaryKey = 'id';
    public $timestamps    = false;

    protected $fillable = [
        'tabla',
        'registro_id',
        'tipo_cambio',
        'campo_modificado',
        'valor_anterior',
        'valor_nuevo',
        'usuario',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'datetime',
    ];
}
