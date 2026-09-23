<?php

namespace App\Exports;

use App\Exports\Sheets\TableSheet;
use App\Models\Expediente;
use App\Models\ContratoPrincipal;
use App\Models\CuentaOperativa;
use App\Models\EjecucionMovimiento;
use App\Models\EstadoEjecucion;
use App\Models\EstadoPrincipal;
use App\Models\HistorialCambio;
use App\Models\Personal;
use App\Models\Sector;
use App\Models\Solicitante;
use App\Models\TipoContratoEjecucion;
use App\Models\TipoContratoPrincipal;
use App\Models\UserRole;
use App\Models\Uvt;
use App\Services\AccessScopeService;
use App\Support\SectorTree;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export consolidado de las tablas del sistema.
 * Cada tabla se exporta como una solapa separada del mismo archivo.
 *
 * El archivo respeta el alcance de quien lo pide: un usuario de gerencia se
 * lleva únicamente los contratos de su Gerencia de Área, sus movimientos y su
 * historial. La información reservada no sale de la Gerencia de Área tampoco
 * por esta vía.
 *
 * Convenciones:
 *   - Se omiten registros con deleted_at (baja lógica).
 *   - No se incluye la columna password de user_roles.
 *   - Las relaciones se cargan en una sola query (eager load) por solapa.
 */
class FullExport implements WithMultipleSheets
{
    public function __construct(
        protected AccessScopeService $scope,
        protected SectorTree $arbol,
    ) {
    }

    public function sheets(): array
    {
        $hojas = [
            $this->tiposEjecucion(),
            $this->estadosEjecucion(),
            $this->solicitantes(),
            $this->sectores(),
            $this->contratos(),
            $this->cuentas(),
            $this->uvts(),
            $this->personal(),
        ];

        // Los usuarios sólo los ve quien puede administrarlos, y acotados a su
        // propio alcance.
        if ($this->scope->usuario()?->puedeAdministrarUsuarios()) {
            $hojas[] = $this->usuarios();
        }

        // Los contratos principales son un módulo retirado: quedan para el
        // administrador de sistema, que es el único sin recorte.
        if ($this->scope->usuario()?->esAdmin()) {
            $hojas[] = $this->tiposPrincipal();
            $hojas[] = $this->estadosPrincipal();
            $hojas[] = $this->contratosPrincipal();
        }

        $hojas[] = $this->expedientes();
        $hojas[] = $this->movimientos();
        $hojas[] = $this->historial();

        return $hojas;
    }

    /**
     * Ids de las cuentas que el usuario puede ver. Los movimientos viven en las
     * cuentas, así que es por acá que se recorta esa solapa.
     *
     * @return array<int>|null  null = sin recorte
     */
    private function cuentasVisibles(): ?array
    {
        return $this->scope->cuentasVisibles();
    }

    /**
     * Ficha de cada contrato, el tercer nivel de la estructura. Los que todavía
     * no la tienen salen igual, con los datos vacíos.
     */
    private function contratos(): TableSheet
    {
        return new TableSheet(
            'Contratos',
            function () {
                $ids  = $this->arbol->nodosDelNivel('contrato');
                $rama = $this->scope->sectoresVisibles();
                if ($rama !== null) {
                    $ids = array_values(array_intersect($ids, $rama));
                }
                return Sector::query()
                    ->whereIn('sector_id', $ids ?: [0])
                    ->with([
                        'contrato.tipo:id,sigla', 'contrato.estado:id,nombre',
                        'contrato.uvt:uvt_id,siglas', 'contrato.solicitante:solicitante_id,razon_social',
                        'contrato.responsable1:legajo,apellido,nombre',
                        'contrato.responsable2:legajo,apellido,nombre',
                    ])
                    ->orderBy('sector_id');
            },
            [
                'ID', 'Contrato', 'Gerencia de Área', 'Gerencia',
                'Tipo', 'Estado', 'UVT', 'Solicitante', 'Resp. 1', 'Resp. 2',
                'Descripción', 'Cliente', 'Caja BAS',
                'F. Inicio', 'F. Vencimiento', 'F. Finalización', 'Acta finalización',
                'Prórroga', 'Renov. autom.',
                'Monto', 'Moneda', 'Cotización', 'Monto en pesos', 'Observaciones',
            ],
            function ($r) {
                $c       = $r->contrato;
                $ramas   = $this->arbol->ancestrosPorNivel((int) $r->sector_id);
                $persona = fn ($p) => $p ? trim("{$p->apellido}, {$p->nombre}", ', ') : null;
                $fecha   = fn ($f) => $f?->format('d/m/Y');
                $sino    = fn ($b) => $c === null ? null : ($b ? 'Sí' : 'No');

                return [
                    $r->sector_id, $r->nombre,
                    $this->arbol->nombre($ramas['gerencia_area']),
                    $this->arbol->nombre($ramas['gerencia']),
                    $c?->tipo?->sigla, $c?->estado?->nombre, $c?->uvt?->siglas,
                    $c?->solicitante?->razon_social,
                    $persona($c?->responsable1), $persona($c?->responsable2),
                    $c?->descripcion_objeto, $c?->cliente, $c?->caja_bas,
                    $fecha($c?->fecha_inicio), $fecha($c?->fecha_vencimiento),
                    $fecha($c?->fecha_finalizacion), $c?->acta_finalizacion,
                    $sino($c?->prorroga), $sino($c?->renovacion_automatica),
                    $c?->monto, $c?->moneda, $c?->cotizacion, $c?->monto_pesos,
                    $c?->observaciones,
                ];
            },
        );
    }

    /** Cuentas operativas con su saldo. */
    private function cuentas(): TableSheet
    {
        return new TableSheet(
            'Cuentas Operativas',
            function () {
                $q = CuentaOperativa::query()->with('sector:sector_id,nombre')->orderBy('id');
                $visibles = $this->cuentasVisibles();
                return $visibles === null ? $q : $q->whereIn('id', $visibles ?: [0]);
            },
            ['ID', 'Nombre', 'Nodo', 'Nivel', 'Ubicación en la estructura', 'Activa',
             'Saldo inicial', 'Ingresos', 'Gastos', 'Saldo'],
            fn ($r) => [
                $r->id, $r->nombre,
                optional($r->sector)->nombre,
                SectorTree::ETIQUETAS[$r->nivel] ?? $r->nivel,
                $r->ruta,
                $r->activo ? 'Sí' : 'No',
                $r->saldo_inicial, $r->ingresos, $r->gastos, $r->saldo,
            ],
        );
    }

    /**
     * Ids de los contratos que el usuario puede ver. Se calcula una sola vez
     * porque lo usan las solapas de movimientos e historial.
     *
     * @return array<int>
     */
    private function contratosVisibles(): array
    {
        return $this->scope
            ->aplicarAContratos(Expediente::query())
            ->pluck('id')->all();
    }

    // ---------- Catálogos ---------------------------------------------

    private function tiposPrincipal(): TableSheet
    {
        return new TableSheet(
            'Tipos Principal',
            TipoContratoPrincipal::query()->orderBy('id'),
            ['ID', 'Sigla', 'Nombre'],
            fn ($r) => [$r->id, $r->sigla, $r->nombre],
        );
    }

    private function tiposEjecucion(): TableSheet
    {
        return new TableSheet(
            'Tipos Ejecucion',
            TipoContratoEjecucion::query()->orderBy('id'),
            ['ID', 'Sigla', 'Nombre'],
            fn ($r) => [$r->id, $r->sigla, $r->nombre],
        );
    }

    private function estadosPrincipal(): TableSheet
    {
        return new TableSheet(
            'Estados Principal',
            EstadoPrincipal::query()->orderBy('id'),
            ['ID', 'Nombre'],
            fn ($r) => [$r->id, $r->nombre],
        );
    }

    private function estadosEjecucion(): TableSheet
    {
        return new TableSheet(
            'Estados Ejecucion',
            EstadoEjecucion::query()->orderBy('id'),
            ['ID', 'Nombre', 'Descripción'],
            fn ($r) => [$r->id, $r->nombre, $r->descripcion],
        );
    }

    private function solicitantes(): TableSheet
    {
        return new TableSheet(
            'Solicitantes',
            Solicitante::query()->orderBy('solicitante_id'),
            ['ID', 'Razón social', 'CUIT/CUIL', 'Rubro', 'Localización', 'Teléfono', 'Contacto'],
            fn ($r) => [
                $r->solicitante_id, $r->razon_social, $r->cuil_cuit,
                $r->rubro, $r->localizacion, $r->telefono, $r->nombre_contacto,
            ],
        );
    }

    private function sectores(): TableSheet
    {
        return new TableSheet(
            'Gerencias',
            function () {
                $q = Sector::query()->with('dependencia:sector_id,nombre')->orderBy('sector_id');
                $rama = $this->scope->sectoresVisibles();
                return $rama === null ? $q : $q->whereIn('sector_id', $rama ?: [0]);
            },
            ['ID', 'Nombre', 'Nivel', 'Depende de', 'Gerencia de Área', 'Responsable', 'Web', 'Ubicación'],
            fn ($r) => [
                $r->sector_id, $r->nombre,
                SectorTree::ETIQUETAS[$r->nivel] ?? $r->nivel,
                optional($r->dependencia)->nombre,
                $r->es_gerencia_area ? 'Sí (es una)' : app(SectorTree::class)->nombre($r->gerenciaAreaId()),
                $r->responsable, $r->web, $r->ubicacion,
            ],
        );
    }

    private function uvts(): TableSheet
    {
        return new TableSheet(
            'UVT',
            Uvt::query()->orderBy('uvt_id'),
            ['ID', 'Siglas', 'Nombre', 'Responsable'],
            fn ($r) => [$r->uvt_id, $r->siglas, $r->nombre, $r->responsable],
        );
    }

    private function personal(): TableSheet
    {
        return new TableSheet(
            'Personal',
            fn () => Personal::query()->with('lugarTrabajo:sector_id,nombre')->orderBy('legajo'),
            ['Legajo', 'Apellido', 'Nombre', 'Mail', 'Interno', 'Lugar de trabajo'],
            fn ($r) => [
                $r->legajo, $r->apellido, $r->nombre, $r->mail, $r->interno,
                optional($r->lugarTrabajo)->nombre,
            ],
        );
    }

    private function usuarios(): TableSheet
    {
        return new TableSheet(
            'Usuarios',
            function () {
                $q = UserRole::query()->with('permisos.sector:sector_id,nombre')->orderBy('username');
                // Los usuarios los administra el administrador del sistema; el
                // resto no ve la nómina.
                $usuario = $this->scope->usuario();
                if ($usuario && !$usuario->esAdmin()) {
                    $q->whereRaw('1 = 0');
                }
                return $q;
            },
            ['ID', 'Username', 'Nombre', 'Email', 'Administrador', 'Permisos', 'Agrupación de saldos', 'Activo', 'Último login'],
            fn ($r) => [
                $r->id, $r->username, $r->display_name, $r->email,
                $r->es_admin ? 'Sí' : 'No',
                $r->permisos->map(fn ($p) => $p->ruta . ' (' . $p->nivel . ')')->implode(' · '),
                $r->saldos_agrupacion,
                $r->activo ? 'Sí' : 'No',
                optional($r->last_login)?->format('d/m/Y H:i'),
            ],
        );
    }

    // ---------- Contratos --------------------------------------------

    private function contratosPrincipal(): TableSheet
    {
        return new TableSheet(
            'Contratos Principal',
            fn () => ContratoPrincipal::query()
                ->with([
                    'estado:id,nombre',
                    'tipoContrato:id,sigla,nombre',
                    'solicitante:solicitante_id,razon_social',
                    'uvt:uvt_id,siglas,nombre',
                    'resp1:legajo,apellido,nombre',
                    'resp2:legajo,apellido,nombre',
                ])
                ->orderBy('id'),
            [
                'ID', 'Expediente', 'F. Apertura', 'Régimen',
                'Tipo', 'Proyecto', 'Descripción',
                'Gerencia área', 'Gerencia',
                'Solicitante', 'Resp. 1', 'Resp. 2',
                'UVT', 'Estado', 'Cliente',
                'F. Inicio', 'F. Vencimiento', 'F. Finalización',
                'Duración (m)', 'Atraso (m)',
                'Acta finalización', 'Prórroga', 'Renov. autom.',
                'Caja BAS',
                'Moneda', 'Cotización',
                'Ejec. ingresos (calc.)', 'Ejec. gastos (calc.)', 'Beneficio (calc.)',
                'Observaciones',
            ],
            fn ($r) => [
                $r->id,
                $r->nro_expediente,
                optional($r->fecha_apertura_expediente)?->format('d/m/Y'),
                $r->regimen,
                optional($r->tipoContrato)->sigla,
                $r->nombre_proyecto,
                $r->descripcion_objeto,
                $r->gerencia_area,
                $r->gerencia,
                optional($r->solicitante)->razon_social,
                $r->resp1 ? trim($r->resp1->apellido . ', ' . $r->resp1->nombre) : null,
                $r->resp2 ? trim($r->resp2->apellido . ', ' . $r->resp2->nombre) : null,
                optional($r->uvt)->siglas,
                optional($r->estado)->nombre,
                $r->cliente,
                optional($r->fecha_inicio)?->format('d/m/Y'),
                optional($r->fecha_vencimiento)?->format('d/m/Y'),
                optional($r->fecha_finalizacion)?->format('d/m/Y'),
                $r->duracion_meses,
                $r->atraso_meses,
                $r->acta_finalizacion,
                $r->prorroga ? 'Sí' : 'No',
                $r->renovacion_automatica ? 'Sí' : 'No',
                $r->caja_bas,
                $r->moneda,
                $r->cotizacion,
                $r->monto_ejecutado_ingresos,
                $r->monto_ejecutado_gastos,
                $r->monto_beneficio,
                $r->observaciones,
            ],
        );
    }

    private function expedientes(): TableSheet
    {
        return new TableSheet(
            'Expedientes',
            fn () => $this->scope->aplicarAContratos(Expediente::query())
                ->with([
                    'sector:sector_id,nombre,dependencia_id',
                    'sector.dependencia:sector_id,nombre',
                    'cuentaOperativa:id,nombre',
                ])
                ->orderBy('id'),
            [
                'ID', 'Expediente',
                'Gerencia de Área', 'Gerencia', 'Contrato', 'Cuenta',
                'Ingresos relacionados', 'Gastos relacionados', 'Resultado',
            ],
            fn ($r) => [
                $r->id,
                $r->nro_expediente,
                $r->estructura['gerencia_area']['nombre'] ?? null,
                $r->estructura['gerencia']['nombre'] ?? null,
                $r->estructura['contrato']['nombre'] ?? null,
                optional($r->cuentaOperativa)->nombre,
                $r->monto_ejecutado_ingresos,
                $r->monto_ejecutado_gastos,
                $r->saldo,
            ],
        );
    }

    private function movimientos(): TableSheet
    {
        return new TableSheet(
            'Movimientos',
            function () {
                $q = EjecucionMovimiento::query()
                    ->with([
                        'cuenta:id,nombre',
                        'cuentaContraparte:id,nombre',
                        'expediente:id,nro_expediente',
                    ])
                    ->orderBy('id');
                $visibles = $this->cuentasVisibles();
                return $visibles === null ? $q : $q->whereIn('cuenta_operativa_id', $visibles ?: [0]);
            },
            [
                'ID', 'Cuenta', 'Expediente', 'Tipo', 'Acción', 'Nº de expediente',
                'Contraparte (tipo)', 'Contraparte',
                'Proveedor', 'Cliente', 'Cuenta contraparte', 'Rubro', 'Moneda',
                'Monto (ARS)', 'Monto (USD)', 'Cotización',
                'Objeto', 'Tiene factura', 'Nombre factura',
                'Creado',
            ],
            fn ($r) => [
                $r->id,
                optional($r->cuenta)->nombre,
                optional($r->expediente)->nro_expediente,
                $r->tipo,
                $r->accion,
                $r->nro_expediente,
                $r->contraparte_tipo,
                $r->contraparte,
                $r->proveedor,
                $r->cliente,
                optional($r->cuentaContraparte)->nombre,
                $r->rubro,
                $r->moneda,
                $r->monto,
                $r->monto_dolares,
                $r->cotizacion,
                $r->objeto,
                $r->has_factura ? 'Sí' : 'No',
                $r->factura_original_name,
                optional($r->created_at)?->format('d/m/Y H:i'),
            ],
        );
    }

    private function historial(): TableSheet
    {
        return new TableSheet(
            'Historial',
            function () {
                $q = HistorialCambio::query()->orderBy('fecha', 'desc');
                if ($this->scope->usuario()?->veTodo()) {
                    return $q;
                }
                // El historial es tan reservado como el contrato al que
                // pertenece: se limita a los que el usuario puede ver.
                $contratos = $this->contratosVisibles() ?: [0];
                return $q->where(function ($w) use ($contratos) {
                    $w->where(function ($x) use ($contratos) {
                        $x->where('tabla', 'expedientes')->whereIn('registro_id', $contratos);
                    })->orWhere(function ($x) use ($contratos) {
                        $x->where('tabla', 'ejecucion_movimientos')
                          ->whereIn('registro_id', EjecucionMovimiento::withTrashed()
                              ->whereIn('expediente_id', $contratos)->select('id'));
                    });
                });
            },
            ['ID', 'Tabla', 'Registro ID', 'Tipo cambio', 'Campo',
             'Valor anterior', 'Valor nuevo', 'Usuario', 'Fecha'],
            fn ($r) => [
                $r->id,
                $r->tabla,
                $r->registro_id,
                $r->tipo_cambio,
                $r->campo_modificado,
                $r->valor_anterior,
                $r->valor_nuevo,
                $r->usuario,
                optional($r->fecha)?->format('d/m/Y H:i'),
            ],
        );
    }
}
