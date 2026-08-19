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
use App\Models\Utt;
use App\Models\Uvt;
use App\Support\SectorTree;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export consolidado de todas las tablas del sistema.
 * Cada tabla se exporta como una solapa separada del mismo archivo.
 *
 * Convenciones:
 *   - Se omiten registros con deleted_at (baja lógica).
 *   - No se incluye la columna password de user_roles.
 *   - Las relaciones se cargan en una sola query (eager load) por solapa.
 */
class FullExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            $this->tiposPrincipal(),
            $this->tiposEjecucion(),
            $this->estadosPrincipal(),
            $this->estadosEjecucion(),
            $this->solicitantes(),
            $this->sectores(),
            $this->utts(),
            $this->uvts(),
            $this->personal(),
            $this->usuarios(),
            $this->contratosPrincipal(),
            $this->contratosEjecucion(),
            $this->movimientos(),
            $this->historial(),
        ];
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
            fn () => Sector::query()->with('dependencia:sector_id,nombre')->orderBy('sector_id'),
            ['ID', 'Nombre', 'Depende de', 'Gerencia de Área', 'Responsable', 'Web', 'Ubicación'],
            fn ($r) => [
                $r->sector_id, $r->nombre,
                optional($r->dependencia)->nombre,
                $r->es_gerencia_area ? 'Sí (es una)' : app(SectorTree::class)->nombre($r->gerenciaAreaId()),
                $r->responsable, $r->web, $r->ubicacion,
            ],
        );
    }

    private function utts(): TableSheet
    {
        return new TableSheet(
            'UTT',
            Utt::query()->orderBy('utt_id'),
            ['ID', 'Sigla', 'Nombre', 'Régimen'],
            fn ($r) => [$r->utt_id, $r->denominacion, $r->nombre, $r->regimen],
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
            fn () => UserRole::query()->with('gerenciaArea:sector_id,nombre')->orderBy('username'),
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
                    'utt:utt_id,denominacion,nombre,regimen',
                    'resp1:legajo,apellido,nombre',
                    'resp2:legajo,apellido,nombre',
                ])
                ->orderBy('id'),
            [
                'ID', 'Expediente', 'F. Apertura', 'Régimen',
                'Tipo', 'Proyecto', 'Descripción',
                'Gerencia área', 'Gerencia',
                'Solicitante', 'Resp. 1', 'Resp. 2',
                'UTT', 'UVT', 'Estado', 'Cliente',
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
                optional($r->utt)->denominacion,
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
            fn () => ContratoEjecucion::query()
                ->with([
                    'estado:id,nombre',
                    'tipoContrato:id,sigla,nombre',
                    'principal:id,nro_expediente,nombre_proyecto',
                    'sector:sector_id,nombre,dependencia_id',
                    'sector.dependencia:sector_id,nombre',
                    'solicitante:solicitante_id,razon_social',
                    'uvt:uvt_id,siglas,nombre',
                    'utt:utt_id,denominacion,nombre,regimen',
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
                'UTT', 'UVT', 'Estado', 'Cliente',
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
                optional($r->utt)->denominacion,
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
            HistorialCambio::query()->orderBy('fecha', 'desc'),
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
