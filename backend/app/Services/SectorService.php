<?php

namespace App\Services;

use App\Models\Personal;
use App\Models\Sector;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectorService extends BaseCrudService
{
    protected string $modelClass = Sector::class;
    protected array $searchableFields = ['nombre', 'responsable', 'ubicacion'];

    public function __construct(
        protected SectorTree $arbol,
        protected CuentaOperativaService $cuentas,
        protected ContratoService $contratos,
        protected AccessScopeService $scope,
    ) {
    }

    /**
     * Alta de un nodo de la estructura.
     *
     * El árbol tiene tres niveles fijos —Gerencia de Área, Gerencia, Contrato—
     * así que no se admite colgar nada de un Contrato. Junto con el nodo se
     * crea su cuenta operativa homónima: toda rama nace con una cuenta a la
     * que imputar expedientes.
     */
    public function create(array $data): Model
    {
        $this->validarProfundidad($data['dependencia_id'] ?? null);

        return DB::transaction(function () use ($data) {
            $sector = parent::create($data);
            $this->arbol->olvidar();
            $this->cuentas->crearParaSector((int) $sector->sector_id, $sector->nombre);
            return $sector;
        });
    }

    public function update(int|string $id, array $data): ?Model
    {
        if (array_key_exists('dependencia_id', $data)) {
            $this->validarProfundidad($data['dependencia_id'], (int) $id);
        }

        $sector = parent::update($id, $data);
        $this->arbol->olvidar();
        return $sector;
    }

    /**
     * La base borra en cascada la ficha y los adjuntos del contrato; los
     * archivos del disco se borran acá, una vez que el nodo ya no está.
     */
    public function delete(int|string $id): bool
    {
        $borrado = parent::delete($id);
        if ($borrado) {
            $this->contratos->borrarArchivosDe((int) $id);
            $this->arbol->olvidar();
        }
        return $borrado;
    }

    /**
     * Un nodo sólo puede colgar de una Gerencia de Área o una Gerencia, y
     * moverlo no puede dejar a sus descendientes por debajo del tercer nivel.
     */
    private function validarProfundidad(?int $dependenciaId, ?int $sectorId = null): void
    {
        $profundidadPadre = $this->arbol->profundidadDe($dependenciaId);

        if ($profundidadPadre >= count(SectorTree::NIVELES)) {
            throw ValidationException::withMessages([
                'dependencia_id' => 'La estructura tiene tres niveles: '
                    . 'Gerencia de Área, Gerencia y Contrato. No se puede colgar nada de un Contrato.',
            ]);
        }

        if ($sectorId !== null) {
            $propia = $this->alturaDe($sectorId);
            if ($profundidadPadre + $propia > count(SectorTree::NIVELES)) {
                throw ValidationException::withMessages([
                    'dependencia_id' => 'Mover la gerencia acá dejaría a las que dependen de ella '
                        . 'por debajo del tercer nivel de la estructura.',
                ]);
            }
        }
    }

    /** Niveles que ocupa el nodo contando sus descendientes (1 si es una hoja). */
    private function alturaDe(int $sectorId): int
    {
        $hijos = $this->arbol->hijosDe($sectorId);
        if (!$hijos) {
            return 1;
        }
        return 1 + max(array_map(fn ($h) => $this->alturaDe($h), $hijos));
    }

    /**
     * Cada usuario ve su rama del árbol y nada más. El administrador de
     * sistema, y quien tiene permiso sobre la raíz, ven todo.
     */
    protected function baseQuery(): Builder
    {
        $visibles = $this->scope->sectoresVisibles();

        return Sector::query()
            ->when($visibles !== null, fn ($q) => $q->whereIn('sector.sector_id', $visibles ?: [0]))
            ->with('dependencia:sector_id,nombre');
    }

    /**
     * Ni el nivel ni el nombre del nodo del que depende son columnas de la
     * tabla: el primero sale de la profundidad y el segundo del padre.
     */
    protected function aplicarOrdenPropio(Builder $query, string $campo, string $dir): bool
    {
        if (!in_array($campo, ['nivel', 'dependencia'], true)) {
            return false;
        }

        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $query->leftJoin('sector as padre', 'padre.sector_id', '=', 'sector.dependencia_id')
              ->select('sector.*');

        if ($campo === 'dependencia') {
            $query->orderBy('padre.nombre', $dir);
        } else {
            $query->orderByRaw('(CASE WHEN sector.dependencia_id IS NULL THEN 1
                                      WHEN padre.dependencia_id IS NULL THEN 2
                                      ELSE 3 END) ' . $dir);
        }

        return true;
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        if (Sector::where('dependencia_id', $id)->exists()) {
            $msgs[] = 'Existen gerencias que dependen de ésta.';
        }
        $pers = Personal::where('lugar_trabajo_id', $id)->count();
        if ($pers > 0) {
            $msgs[] = "Existen {$pers} persona(s) con lugar de trabajo en esta gerencia.";
        }
        return $msgs;
    }
}
