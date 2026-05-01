<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    protected $table      = 'sector';
    protected $primaryKey = 'sector_id';
    public $timestamps    = false;

    protected $fillable = [
        'nombre',
        'dependencia_id',
        'responsable',
        'web',
        'ubicacion',
    ];

    public function dependencia()
    {
        return $this->belongsTo(Sector::class, 'dependencia_id', 'sector_id');
    }

    public function dependientes()
    {
        return $this->hasMany(Sector::class, 'dependencia_id', 'sector_id');
    }

    public function personal()
    {
        return $this->hasMany(Personal::class, 'lugar_trabajo_id', 'sector_id');
    }

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'sector_id', 'sector_id');
    }
}
