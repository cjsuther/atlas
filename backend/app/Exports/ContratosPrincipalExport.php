<?php

namespace App\Exports;

use App\Services\ContratoPrincipalService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContratosPrincipalExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        protected array $filters,
        protected ContratoPrincipalService $service,
    ) {}

    public function query()
    {
        return $this->service->buildQuery($this->filters);
    }

    public function headings(): array
    {
        return [
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
        ];
    }

    public function map($r): array
    {
        return [
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
        ];
    }

    public function title(): string
    {
        return 'Contratos Principal';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A2E4A'],
                ],
            ],
        ];
    }
}
