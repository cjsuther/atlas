<?php

namespace App\Models;

use App\Services\AccessScopeService;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Expediente: lo que se imputa a una cuenta operativa.
 *
 * Guarda su número y la cuenta; la rama (`sector_id`) se deduce de esa cuenta y
 * queda como copia derivada, que es por donde filtran el alcance y el panel.
 *
 * «Contrato» no es esto: es el tercer nivel de la estructura.
 */
class Expediente extends Model
{
    use SoftDeletes;

    protected $table      = 'expedientes';
    protected $primaryKey = 'id';
    public $timestamps    = true;

    protected $fillable = [
        'nro_expediente',
        'sector_id',
        'cuenta_operativa_id',
    ];

    protected $appends = [
        'monto_ejecutado_ingresos', 'monto_ejecutado_gastos',
        'saldo', 'gerencia_area', 'estructura',
    ];

    // ----------------------------------------------------------------------
    // Relaciones
    // ----------------------------------------------------------------------

    /** Rama del árbol a la que pertenece, deducida de la cuenta. */
    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    /** Cuenta operativa a la que se imputa el expediente. */
    public function cuentaOperativa()
    {
        return $this->belongsTo(CuentaOperativa::class, 'cuenta_operativa_id');
    }


    public function movimientos()
    {
        return $this->hasMany(EjecucionMovimiento::class, 'expediente_id', 'id');
    }

    // ----------------------------------------------------------------------
    // Campos calculados
    // ----------------------------------------------------------------------

    /**
     * Gerencia de Área del expediente: el ancestro raíz de su sector. Se expone
     * como atributo porque toda la lectura del sistema —alcance, panel,
     * exportaciones— se apoya en ella.
     *
     * @return array{sector_id: int, nombre: string}|null
     */
    public function getGerenciaAreaAttribute(): ?array
    {
        if ($this->sector_id === null) {
            return null;
        }

        $arbol = app(SectorTree::class);
        $raiz  = $arbol->raizDe((int) $this->sector_id);
        if ($raiz === null || !app(AccessScopeService::class)->veSector($raiz)) {
            return null;
        }

        return ['sector_id' => $raiz, 'nombre' => (string) $arbol->nombre($raiz)];
    }

    /**
     * Dónde está el expediente en cada nivel de la estructura: Gerencia de
     * Área, Gerencia y Contrato. Los niveles por debajo del nodo al que
     * se imputa quedan en null.
     *
     * @return array<string, array{sector_id: int, nombre: string}|null>
     */
    public function getEstructuraAttribute(): array
    {
        $arbol = app(SectorTree::class);
        $ids   = $arbol->ancestrosPorNivel($this->sector_id !== null ? (int) $this->sector_id : null);

        $scope = app(AccessScopeService::class);

        return array_map(
            fn ($id) => $id === null || !$scope->veSector($id)
                ? null
                : ['sector_id' => $id, 'nombre' => (string) $arbol->nombre($id)],
            $ids,
        );
    }



    /**
     * Suma de movimientos tipo "ingreso" (en pesos). Se calcula a partir de:
     * 1) subquery precargado por el Service como `sum_ingresos`
     * 2) o, en su defecto, sumando la relación cargada
     * 3) o, último recurso, una consulta directa.
     */
    public function getMontoEjecutadoIngresosAttribute(): float
    {
        return $this->sumMovimientos('ingreso', 'sum_ingresos');
    }

    public function getMontoEjecutadoGastosAttribute(): float
    {
        return $this->sumMovimientos('gasto', 'sum_gastos');
    }

    /**
     * Resultado del contrato: lo que entró menos lo que salió por los
     * movimientos registrados contra él. El saldo
     * disponible no es del contrato sino de la cuenta.
     */
    public function getSaldoAttribute(): float
    {
        return round($this->monto_ejecutado_ingresos - $this->monto_ejecutado_gastos, 2);
    }

    private function sumMovimientos(string $tipo, string $aliasPrecargado): float
    {
        if (array_key_exists($aliasPrecargado, $this->attributes)) {
            return round((float) ($this->attributes[$aliasPrecargado] ?? 0), 2);
        }
        if ($this->relationLoaded('movimientos')) {
            return round((float) $this->movimientos->where('tipo', $tipo)->sum('monto'), 2);
        }
        return round((float) $this->movimientos()->where('tipo', $tipo)->sum('monto'), 2);
    }
}
