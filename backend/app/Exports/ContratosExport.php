<?php

namespace App\Exports;

use App\Services\ContratoService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContratosExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        protected array $filters,
        protected ContratoService $service,
    ) {}

    public function query()
    {
        return $this->service->buildQuery($this->filters);
    }

    public function headings(): array
    {
        return [
            'ID', 'Proyecto', 'Expediente', 'Tipo', 'Estado',
            'Solicitante', 'UVT', 'Sector',
            'Gerencia', 'Área',
            'F. Firma', 'F. Inicio', 'F. Vencimiento', 'F. Finalizado',
            'Duración (m)', 'Atraso (m)',
            'Monto $', 'Monto USD', 'Monto EUR', 'Monto otro', 'Moneda otro',
            'Resp. 1', 'Resp. 2',
            'Prórroga', 'Renov. autom.',
            'Descripción del objeto',
            'Observaciones',
        ];
    }

    public function map($r): array
    {
        return [
            $r->id_cto,
            $r->nombre_proy,
            $r->expediente,
            optional($r->tipoContrato)->tipo,
            optional($r->estado)->estado_nombre,
            optional($r->solicitante)->razon_social,
            optional($r->uvt)->siglas,
            optional($r->sector)->nombre,
            $r->gerencia,
            $r->gerencia_area,
            optional($r->fecha_firma)?->format('d/m/Y'),
            optional($r->fecha_inicio)?->format('d/m/Y'),
            optional($r->fecha_vencimiento)?->format('d/m/Y'),
            optional($r->fecha_finalizado)?->format('d/m/Y'),
            $r->duracion_meses,
            $r->atraso_meses,
            $r->monto_pesos,
            $r->monto_usd,
            $r->monto_euros,
            $r->monto_otro,
            $r->moneda_otro,
            optional($r->resp1)?->getNombreCompletoAttribute(),
            optional($r->resp2)?->getNombreCompletoAttribute(),
            $r->prorroga ? 'Sí' : 'No',
            $r->renovacion_automatica ? 'Sí' : 'No',
            $r->descripcion_objeto,
            $r->observaciones,
        ];
    }

    public function title(): string
    {
        return 'Contratos';
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
