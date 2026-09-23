<?php

namespace App\Services;

use App\Models\Contrato;
use App\Models\ContratoArchivo;
use App\Models\Sector;
use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Contratos: los nodos del tercer nivel de la estructura, con su ficha.
 *
 * El nodo se da de alta, se mueve y se borra desde la Estructura; acá sólo se
 * completa la ficha. Un contrato sin ficha aparece igual, con los datos vacíos,
 * y la ficha se crea la primera vez que se guarda.
 *
 * Se ven los contratos de la rama del usuario y se editan los de la rama en la
 * que tiene escritura.
 */
class ContratoService
{
    /** Disco privado donde quedan los adjuntos; se descargan sólo por la API. */
    public const ARCHIVOS_DISK = 'local';
    public const ARCHIVOS_DIR  = 'contratos';

    /** Columnas de la ficha por las que se puede ordenar tal cual. */
    private const ORDENABLES = [
        'cliente', 'caja_bas', 'fecha_inicio', 'fecha_vencimiento',
        'fecha_finalizacion', 'monto', 'moneda',
    ];

    public function __construct(
        protected SectorTree $arbol,
        protected AccessScopeService $scope,
    ) {
    }

    public function paginate(array $params): LengthAwarePaginator
    {
        $q = $this->baseQuery();

        if (!empty($params['search'])) {
            $term = '%' . $params['search'] . '%';
            $q->where(function (Builder $w) use ($term) {
                $w->where('sector.nombre', 'like', $term)
                  ->orWhere('contratos.cliente', 'like', $term)
                  ->orWhere('contratos.caja_bas', 'like', $term)
                  ->orWhere('contratos.descripcion_objeto', 'like', $term);
            });
        }

        $this->ordenar($q, (string) ($params['order_by'] ?? ''), (string) ($params['order_dir'] ?? 'asc'));

        $perPage   = max(1, min((int) ($params['per_page'] ?? 20), 500));
        $paginador = $q->paginate($perPage);
        $paginador->setCollection($paginador->getCollection()->map(fn ($s) => $this->fila($s)));

        return $paginador;
    }

    public function find(int $sectorId): ?array
    {
        $sector = $this->baseQuery()->where('sector.sector_id', $sectorId)->first();
        return $sector ? $this->fila($sector) : null;
    }

    /** Guarda la ficha del contrato; si no la tenía, la crea. */
    public function guardar(int $sectorId, array $data): array
    {
        // En pesos la cotización no aplica.
        if (($data['moneda'] ?? null) === 'Peso') {
            $data['cotizacion'] = null;
        }

        $ficha = Contrato::firstOrNew(['sector_id' => $sectorId]);
        $ficha->fill($data);
        $ficha->save();

        return $this->find($sectorId);
    }

    // ------------------------------------------------------------------
    // Archivos adjuntos
    // ------------------------------------------------------------------

    /** @return Collection<int, ContratoArchivo> */
    public function archivos(int $sectorId): Collection
    {
        return ContratoArchivo::where('sector_id', $sectorId)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function adjuntar(int $sectorId, UploadedFile $archivo, ?string $descripcion): ContratoArchivo
    {
        // Nombre al azar con la extensión original: el nombre que eligió el
        // usuario se guarda aparte y es el que lleva la descarga.
        $extension = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs(
            self::ARCHIVOS_DIR . "/{$sectorId}",
            Str::random(40) . ($extension !== '' ? ".{$extension}" : ''),
            self::ARCHIVOS_DISK,
        );

        return ContratoArchivo::create([
            'sector_id'       => $sectorId,
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta'            => $ruta,
            'mime'            => $archivo->getMimeType(),
            'tamano'          => $archivo->getSize(),
            'descripcion'     => $descripcion,
            'subido_por'      => Auth::user()?->username,
        ]);
    }

    public function quitarArchivo(ContratoArchivo $archivo): void
    {
        Storage::disk(self::ARCHIVOS_DISK)->delete($archivo->ruta);
        $archivo->delete();
    }

    /**
     * Al borrar el nodo, la base se lleva los registros en cascada; los
     * archivos del disco hay que borrarlos a mano.
     */
    public function borrarArchivosDe(int $sectorId): void
    {
        Storage::disk(self::ARCHIVOS_DISK)->deleteDirectory(self::ARCHIVOS_DIR . "/{$sectorId}");
    }

    public function esContrato(int $sectorId): bool
    {
        return $this->arbol->nivelDe($sectorId) === 'contrato';
    }

    public function puedeVer(int $sectorId): bool
    {
        $ids = $this->scope->sectoresVisibles();
        return $ids === null || in_array($sectorId, $ids, true);
    }

    public function puedeEditar(int $sectorId): bool
    {
        $ids = $this->scope->sectoresEditables();
        return $ids === null || in_array($sectorId, $ids, true);
    }

    private function baseQuery(): Builder
    {
        $ids = $this->arbol->nodosDelNivel('contrato');

        $visibles = $this->scope->sectoresVisibles();
        if ($visibles !== null) {
            $ids = array_values(array_intersect($ids, $visibles));
        }

        return Sector::query()
            ->leftJoin('contratos', 'contratos.sector_id', '=', 'sector.sector_id')
            ->select('sector.*')
            ->selectSub(
                ContratoArchivo::query()->selectRaw('COUNT(*)')->whereColumn('contrato_archivos.sector_id', 'sector.sector_id'),
                'archivos_count'
            )
            ->whereIn('sector.sector_id', $ids ?: [0])
            ->with([
                'contrato.tipo:id,sigla,nombre',
                'contrato.estado:id,nombre',
                'contrato.uvt:uvt_id,siglas,nombre',
                'contrato.solicitante:solicitante_id,razon_social',
                'contrato.responsable1:legajo,apellido,nombre',
                'contrato.responsable2:legajo,apellido,nombre',
            ]);
    }

    /**
     * La Gerencia y la Gerencia de Área salen de los ancestros del nodo; el
     * tipo, el estado y la UVT, de sus catálogos.
     */
    private function ordenar(Builder $q, string $campo, string $dir): void
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        match (true) {
            $campo === 'nombre' => $q->orderBy('sector.nombre', $dir),
            in_array($campo, ['gerencia', 'gerencia_area'], true) => $q
                ->leftJoin('sector as ger', 'ger.sector_id', '=', 'sector.dependencia_id')
                ->leftJoin('sector as area', 'area.sector_id', '=', 'ger.dependencia_id')
                ->orderBy($campo === 'gerencia' ? 'ger.nombre' : 'area.nombre', $dir)
                ->orderBy('sector.nombre'),
            $campo === 'tipo' => $q
                ->leftJoin('tipo_contrato_ejecucion as t', 't.id', '=', 'contratos.tipo_contrato_id')
                ->orderBy('t.sigla', $dir),
            $campo === 'estado' => $q
                ->leftJoin('estado_ejecucion as e', 'e.id', '=', 'contratos.estado_id')
                ->orderBy('e.nombre', $dir),
            $campo === 'uvt' => $q
                ->leftJoin('uvt as u', 'u.uvt_id', '=', 'contratos.uvt_id')
                ->orderBy('u.siglas', $dir),
            $campo === 'monto_pesos' => $q->orderByRaw(
                "(CASE WHEN contratos.moneda = 'Peso' THEN contratos.monto
                       ELSE contratos.monto * contratos.cotizacion END) {$dir}"
            ),
            $campo === 'archivos' => $q->orderBy('archivos_count', $dir),
            in_array($campo, self::ORDENABLES, true) => $q->orderBy("contratos.{$campo}", $dir),
            default => $q->orderBy('sector.nombre'),
        };
    }

    /**
     * Una fila por contrato: el nodo, su ubicación y la ficha aplanada, que es
     * como la usa el formulario. Sin ficha, los campos van vacíos.
     */
    private function fila(Sector $s): array
    {
        $id    = (int) $s->sector_id;
        $ramas = $this->arbol->ancestrosPorNivel($id);
        $ficha = $s->contrato;

        $campos = array_fill_keys((new Contrato())->getFillable(), null);
        unset($campos['sector_id']);
        $campos['moneda']                = 'Peso';
        $campos['prorroga']              = false;
        $campos['renovacion_automatica'] = false;

        if ($ficha) {
            foreach (array_keys($campos) as $c) {
                $campos[$c] = $ficha->{$c};
            }
            // Las fechas, como las espera un <input type="date">.
            foreach (['fecha_inicio', 'fecha_vencimiento', 'fecha_finalizacion'] as $f) {
                $campos[$f] = $ficha->{$f}?->format('Y-m-d');
            }
        }

        $persona = fn ($p) => $p ? trim("{$p->apellido}, {$p->nombre}", ', ') : null;

        return [
            'sector_id'     => $id,
            'nombre'        => $s->nombre,
            'gerencia_area' => $this->arbol->nombre($ramas['gerencia_area']),
            'gerencia'      => $this->arbol->nombre($ramas['gerencia']),
            ...$campos,
            'monto_pesos'   => $ficha?->monto_pesos,
            'tipo'          => $ficha?->tipo?->sigla,
            'estado'        => $ficha?->estado?->nombre,
            'uvt'           => $ficha?->uvt?->siglas,
            'solicitante'   => $ficha?->solicitante?->razon_social,
            'responsable1'  => $persona($ficha?->responsable1),
            'responsable2'  => $persona($ficha?->responsable2),
            'archivos'      => (int) ($s->archivos_count ?? 0),
            'tiene_ficha'   => $ficha !== null,
            'editable'      => $this->puedeEditar($id),
        ];
    }
}
