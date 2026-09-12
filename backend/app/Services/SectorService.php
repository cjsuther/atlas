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
     * Un nodo sólo puede colgar de una Gerencia de Área o de una Gerencia, y
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
                    'dependencia_id' => 'Mover el sector acá dejaría a los que dependen de él '
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

    protected function baseQuery(): Builder
    {
        return Sector::query()->with('dependencia:sector_id,nombre');
    }

    public function dependenciesFor(int|string $id): array
    {
        $msgs = [];
        if (Sector::where('dependencia_id', $id)->exists()) {
            $msgs[] = 'Existen sectores que dependen de éste.';
        }
        $pers = Personal::where('lugar_trabajo_id', $id)->count();
        if ($pers > 0) {
            $msgs[] = "Existen {$pers} persona(s) con lugar de trabajo en este sector.";
        }
        return $msgs;
    }
}
