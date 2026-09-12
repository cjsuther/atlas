<?php

namespace App\Models;

use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Estructura organizativa. La tabla se referencia a sí misma y arma un árbol de
 * tres niveles fijos, dados por la profundidad del nodo:
 *
 *   Gerencia de Área  ->  Gerencia  ->  Contrato
 *
 * De cualquiera de esos nodos cuelgan cuentas operativas, y a una cuenta se
 * imputan los expedientes. La Gerencia de Área sigue siendo el límite de
 * confidencialidad: la información no sale de ella.
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

    protected $appends = ['es_gerencia_area', 'nivel'];

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

    /** Cuentas operativas que cuelgan de este nodo. */
    public function cuentasOperativas()
    {
        return $this->hasMany(CuentaOperativa::class, 'sector_id', 'sector_id');
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

    /** Nivel del árbol en el que está el nodo, según su profundidad. */
    public function getNivelAttribute(): string
    {
        return app(SectorTree::class)->nivelDe($this->sector_id !== null ? (int) $this->sector_id : null);
    }

    /** Gerencia de Área a la que pertenece este sector. */
    public function gerenciaAreaId(): ?int
    {
        return app(SectorTree::class)->raizDe((int) $this->sector_id);
    }
}
