<?php

namespace App\Models;

use App\Services\AccessScopeService;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta operativa: donde vive la plata y donde se registra la operatoria.
 *
 * Cuelga siempre de un nodo del árbol de la estructura —Gerencia de Área,
 * Gerencia o Contrato— y un nodo puede tener varias.
 *
 * Cada cuenta tiene su saldo inicial y su historial de movimientos: ingresos y
 * gastos por factura, transferencias con otra cuenta, incentivos y MCH. Cada
 * movimiento puede indicar el contrato con el que se relaciona, pero no está
 * obligado: una cuenta puede acumular ingresos de varios convenios.
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
        'saldo_inicial',
        'activo',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'saldo_inicial' => 'decimal:2',
    ];

    protected $appends = ['nivel', 'ruta', 'ingresos', 'gastos', 'saldo'];

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    public function expedientes()
    {
        return $this->hasMany(Expediente::class, 'cuenta_operativa_id');
    }

    public function movimientos()
    {
        return $this->hasMany(EjecucionMovimiento::class, 'cuenta_operativa_id');
    }

    /**
     * Lo que entró y lo que salió de la cuenta, en pesos. El servicio precarga
     * las sumas como `sum_ingresos` y `sum_gastos`; si no vienen, se consultan.
     */
    public function getIngresosAttribute(): float
    {
        return $this->sumMovimientos('ingreso', 'sum_ingresos');
    }

    public function getGastosAttribute(): float
    {
        return $this->sumMovimientos('gasto', 'sum_gastos');
    }

    /** Saldo de la cuenta: con lo que arrancó más lo que entró menos lo que salió. */
    public function getSaldoAttribute(): float
    {
        return round((float) $this->saldo_inicial + $this->ingresos - $this->gastos, 2);
    }

    private function sumMovimientos(string $tipo, string $precargado): float
    {
        if (array_key_exists($precargado, $this->attributes)) {
            return round((float) $this->attributes[$precargado], 2);
        }
        if ($this->relationLoaded('movimientos')) {
            return round((float) $this->movimientos->where('tipo', $tipo)->sum('monto'), 2);
        }
        if ($this->id === null) {
            return 0.0;
        }
        return round((float) $this->movimientos()->where('tipo', $tipo)->sum('monto'), 2);
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

    /**
     * Camino hasta el nodo, para mostrar en pantalla. Se nombran sólo los
     * nodos que el usuario puede ver: quien tiene permiso sobre un Contrato no
     * se entera de qué Gerencia cuelga.
     */
    public function getRutaAttribute(): string
    {
        $arbol = app(SectorTree::class);
        $scope = app(AccessScopeService::class);

        $nombres = [];
        foreach ($arbol->ancestrosPorNivel($this->sector_id) as $id) {
            if ($id !== null && $scope->veSector($id)) {
                $nombres[] = (string) $arbol->nombre($id);
            }
        }

        return $nombres ? implode(' › ', $nombres) : $arbol->rutaDe($this->sector_id);
    }
}
