<?php

namespace App\Http\Controllers;

use App\Exports\FullExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /** GET /api/export/excel — descarga todas las tablas en un único Excel multi-solapa. */
    public function full(): BinaryFileResponse
    {
        $filename = 'atlas-export-' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new FullExport(), $filename);
    }
}
