<?php

namespace App\Exports;

use App\Exports\Sheets\TableSheet;
use App\Models\ContratoEjecucion;
use App\Models\ContratoPrincipal;
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
        if ($this->scope->usuario()?->isAdminSistema()) {
            $hojas[] = $this->tiposPrincipal();
            $hojas[] = $this->estadosPrincipal();
            $hojas[] = $this->contratosPrincipal();
        }

        $hojas[] = $this->contratosEjecucion();
        $hojas[] = $this->movimientos();
        $hojas[] = $this->historial();

        return $hojas;
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
            ->aplicarAContratos(ContratoEjecucion::query())
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
            'Sectores',
            function () {
                $q = Sector::query()->with('dependencia:sector_id,nombre')->orderBy('sector_id');
                $rama = $this->scope->sectoresVisibles();
                return $rama === null ? $q : $q->whereIn('sector_id', $rama ?: [0]);
            },
            ['ID', 'Nombre', 'Depende de', 'Gerencia de Área', 'Responsable', 'Web', 'Ubicación'],
            fn ($r) => [
                $r->sector_id, $r->nombre,
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
                $q = UserRole::query()->with('gerenciaArea:sector_id,nombre')->orderBy('username');
                $usuario = $this->scope->usuario();
                if ($usuario && !$usuario->isAdminSistema()) {
                    $q->where('sector_id', $usuario->sector_id);
                }
                return $q;
            },
            ['ID', 'Username', 'Nombre', 'Email', 'Rol', 'Gerencia de Área', 'Agrupación de saldos', 'Activo', 'Último login'],
            fn ($r) => [
                $r->id, $r->username, $r->display_name, $r->email, $r->rol,
                optional($r->gerenciaArea)->nombre,
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

    private function contratosEjecucion(): TableSheet
    {
        return new TableSheet(
            'Contratos Ejecucion',
            fn () => $this->scope->aplicarAContratos(ContratoEjecucion::query())
                ->with([
                    'estado:id,nombre',
                    'tipoContrato:id,sigla,nombre',
                    'principal:id,nro_expediente,nombre_proyecto',
                    'sector:sector_id,nombre,dependencia_id',
                    'sector.dependencia:sector_id,nombre',
                    'solicitante:solicitante_id,razon_social',
                    'uvt:uvt_id,siglas,nombre',
                    'resp1:legajo,apellido,nombre',
                    'resp2:legajo,apellido,nombre',
                ])
                ->orderBy('id'),
            [
                'ID', 'Expediente', 'F. Apertura',
                'Tipo', 'Proyecto', 'Descripción',
                'Contrato Principal (histórico)',
                'Gerencia de Área', 'Sector',
                'Solicitante', 'Resp. 1', 'Resp. 2',
                'UVT', 'Estado', 'Cliente',
                'F. Inicio', 'F. Vencimiento', 'F. Finalización',
                'Duración (m)', 'Atraso (m)',
                'Acta finalización', 'Prórroga', 'Renov. autom.',
                'Caja BAS',
                'Moneda', 'Cotización',
                'Saldo inicial',
                'Ejec. ingresos (calc.)', 'Ejec. gastos (calc.)', 'Saldo (calc.)',
                'Observaciones',
            ],
            fn ($r) => [
                $r->id,
                $r->nro_expediente,
                optional($r->fecha_apertura_expediente)?->format('d/m/Y'),
                optional($r->tipoContrato)->sigla,
                $r->nombre_proyecto,
                $r->descripcion_objeto,
                $r->principal ? "#{$r->principal->id} — " . $r->principal->nro_expediente : null,
                optional($r->gerencia_area)['nombre'] ?? null,
                optional($r->sector)->nombre,
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
                $r->saldo_inicial,
                $r->monto_ejecutado_ingresos,
                $r->monto_ejecutado_gastos,
                $r->saldo,
                $r->observaciones,
            ],
        );
    }

    private function movimientos(): TableSheet
    {
        return new TableSheet(
            'Movimientos',
            fn () => EjecucionMovimiento::query()
                ->whereIn('contrato_ejecucion_id', $this->contratosVisibles())
                ->with([
                    'contratoEjecucion:id,nro_expediente',
                    'contratoContraparte:id,nro_expediente',
                ])
                ->orderBy('id'),
            [
                'ID', 'Contrato (expediente)', 'Tipo', 'Acción', 'Expediente',
                'Contraparte (tipo)', 'Contraparte',
                'Proveedor', 'Cliente', 'Contrato contraparte', 'Rubro', 'Moneda',
                'Monto (ARS)', 'Monto (USD)', 'Cotización',
                'Objeto', 'Tiene factura', 'Nombre factura',
                'Creado',
            ],
            fn ($r) => [
                $r->id,
                optional($r->contratoEjecucion)->nro_expediente,
                $r->tipo,
                $r->accion,
                $r->nro_expediente,
                $r->contraparte_tipo,
                $r->contraparte,
                $r->proveedor,
                $r->cliente,
                optional($r->contratoContraparte)->nro_expediente,
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
                        $x->where('tabla', 'contratos_ejecucion')->whereIn('registro_id', $contratos);
                    })->orWhere(function ($x) use ($contratos) {
                        $x->where('tabla', 'ejecucion_movimientos')
                          ->whereIn('registro_id', EjecucionMovimiento::withTrashed()
                              ->whereIn('contrato_ejecucion_id', $contratos)->select('id'));
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
