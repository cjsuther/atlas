<?php

namespace App\Models;

use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Estructura organizativa. La tabla se referencia a sí misma:
 *
 *   - Un sector sin dependencia es una Gerencia de Área. Es el nivel al que se
 *     asocian los administradores y operadores de gerencia, y el límite de
 *     confidencialidad: la información no sale de la Gerencia de Área.
 *   - Los sectores dependientes son sus subsectores.
 */
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

    protected $appends = ['es_gerencia_area'];

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
        return $this->hasMany(ContratoEjecucion::class, 'sector_id', 'sector_id');
    }

    /** Sólo las Gerencias de Área (sectores sin dependencia). */
    public function scopeGerenciasArea($query)
    {
        return $query->whereNull('dependencia_id');
    }

    public function getEsGerenciaAreaAttribute(): bool
    {
        return $this->dependencia_id === null;
    }

    /** Gerencia de Área a la que pertenece este sector. */
    public function gerenciaAreaId(): ?int
    {
        return app(SectorTree::class)->raizDe((int) $this->sector_id);
    }
}
