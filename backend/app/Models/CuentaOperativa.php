<?php

namespace App\Models;

use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta operativa: la unidad a la que se imputan los expedientes.
 *
 * Cuelga siempre de un nodo del árbol de la estructura —Gerencia de Área,
 * Gerencia o Contrato— y un nodo puede tener varias.
 *
 * La rama de un expediente no se guarda: se deduce del nodo de su cuenta.
 */
class CuentaOperativa extends Model
{
    protected $table = 'cuentas_operativas';

    protected $fillable = [
        'nombre',
        'sector_id',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    protected $appends = ['nivel', 'ruta'];

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    public function expedientes()
    {
        return $this->hasMany(ContratoEjecucion::class, 'cuenta_operativa_id');
    }

    /** Nodo del árbol del que cuelga, con sus ancestros. @return array<int> */
    public function rama(): array
    {
        return $this->sector_id === null
            ? []
            : app(SectorTree::class)->ramaDe((int) $this->sector_id);
    }

    /** Gerencia de Área a la que pertenece la cuenta, si no es la de organización. */
    public function gerenciaAreaId(): ?int
    {
        return $this->sector_id === null
            ? null
            : app(SectorTree::class)->raizDe((int) $this->sector_id);
    }

    /** En qué nivel del árbol está la cuenta. */
    public function getNivelAttribute(): string
    {
        return app(SectorTree::class)->nivelDe($this->sector_id);
    }

    /** Camino desde la Gerencia de Área hasta el nodo, para mostrar en pantalla. */
    public function getRutaAttribute(): string
    {
        return app(SectorTree::class)->rutaDe($this->sector_id);
    }
}
