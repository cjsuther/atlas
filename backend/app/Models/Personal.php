<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Personal extends Model
{
    protected $table      = 'personal';
    protected $primaryKey = 'legajo';
    public $incrementing  = false;
    protected $keyType    = 'int';
    public $timestamps    = false;

    protected $fillable = [
        'legajo',
        'apellido',
        'nombre',
        'interno',
        'mail',
        'lugar_trabajo_id',
    ];

    public function lugarTrabajo()
    {
        return $this->belongsTo(Sector::class, 'lugar_trabajo_id', 'sector_id');
    }

    public function contratosResp1()
    {
        return $this->hasMany(Contrato::class, 'resp1_id', 'legajo');
    }

    public function contratosResp2()
    {
        return $this->hasMany(Contrato::class, 'resp2_id', 'legajo');
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->apellido}, {$this->nombre}");
    }
}
