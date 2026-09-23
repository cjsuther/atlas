<?php

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\ContratoArchivo;
use App\Services\ContratoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ficha de los contratos, el tercer nivel de la estructura.
 *
 * No hay alta ni baja: el nodo nace y se borra en la Estructura. Acá se
 * consulta la lista y se completa la ficha de cada uno.
 */
class ContratoController extends Controller
{
    public function __construct(protected ContratoService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->paginate($request->all()));
    }

    public function show(int $id): JsonResponse
    {
        $fila = $this->service->puedeVer($id) ? $this->service->find($id) : null;
        if (!$fila) {
            return $this->noEncontrado();
        }
        return response()->json(['data' => $fila]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->service->esContrato($id) || !$this->service->puedeVer($id)) {
            return $this->noEncontrado();
        }
        if (!$this->service->puedeEditar($id)) {
            return $this->sinPermiso();
        }

        $data = $request->validate([
            'tipo_contrato_id'      => ['nullable', 'integer', 'exists:tipo_contrato_ejecucion,id'],
            'estado_id'             => ['nullable', 'integer', 'exists:estado_ejecucion,id'],
            'uvt_id'                => ['nullable', 'integer', 'exists:uvt,uvt_id'],
            'solicitante_id'        => ['nullable', 'integer', 'exists:solicitantes,solicitante_id'],
            'resp1_id'              => ['nullable', 'integer', 'exists:personal,legajo'],
            'resp2_id'              => ['nullable', 'integer', 'exists:personal,legajo'],
            'descripcion_objeto'    => ['nullable', 'string'],
            'cliente'               => ['nullable', 'string', 'max:300'],
            'caja_bas'              => ['nullable', 'string', 'max:200'],
            'fecha_inicio'          => ['nullable', 'date'],
            'fecha_vencimiento'     => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_finalizacion'    => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'acta_finalizacion'     => ['nullable', 'string', 'max:500'],
            'prorroga'              => ['nullable', 'boolean'],
            'renovacion_automatica' => ['nullable', 'boolean'],
            'monto'                 => ['nullable', 'numeric'],
            'moneda'                => ['required', Rule::in(Contrato::MONEDAS)],
            'cotizacion'            => ['nullable', 'numeric', 'gt:0', 'required_unless:moneda,Peso'],
            'observaciones'         => ['nullable', 'string'],
        ], [
            'moneda.in'                         => 'La moneda debe ser Peso, Dólar o Euro.',
            'cotizacion.required_unless'        => 'La cotización es obligatoria cuando la moneda no es Peso: '
                                                 . 'es la que lleva el monto a pesos.',
            'cotizacion.gt'                     => 'La cotización debe ser mayor que cero.',
            'fecha_vencimiento.after_or_equal'  => 'La fecha de vencimiento debe ser igual o posterior a la de inicio.',
            'fecha_finalizacion.after_or_equal' => 'La fecha de finalización no puede ser anterior a la de inicio.',
        ]);

        foreach (['prorroga', 'renovacion_automatica'] as $b) {
            $data[$b] = (bool) ($data[$b] ?? false);
        }

        return response()->json(['data' => $this->service->guardar($id, $data)]);
    }

    // ------------------------------------------------------------------
    // Archivos adjuntos
    //   ver y descargar: alcance de lectura sobre el contrato
    //   subir y quitar:  escritura sobre su rama
    // ------------------------------------------------------------------

    /** Tipos admitidos: documentos, planillas, imágenes y comprimidos. */
    private const EXTENSIONES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,rtf,jpg,jpeg,png,gif,zip,rar,7z';

    /** GET /api/contratos/{id}/archivos */
    public function archivos(int $id): JsonResponse
    {
        if (!$this->service->esContrato($id) || !$this->service->puedeVer($id)) {
            return $this->noEncontrado();
        }
        return response()->json(['data' => $this->service->archivos($id)]);
    }

    /** POST /api/contratos/{id}/archivos — multipart: archivo, descripcion */
    public function adjuntar(Request $request, int $id): JsonResponse
    {
        if (!$this->service->esContrato($id) || !$this->service->puedeVer($id)) {
            return $this->noEncontrado();
        }
        if (!$this->service->puedeEditar($id)) {
            return $this->sinPermiso();
        }

        $data = $request->validate([
            // La extensión y el contenido tienen que coincidir con un tipo admitido.
            'archivo'     => ['required', 'file', 'extensions:' . self::EXTENSIONES,
                              'mimes:' . self::EXTENSIONES, 'max:20480'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ], [
            'archivo.required' => 'Elija el archivo a adjuntar.',
            'archivo.uploaded' => 'No se pudo subir el archivo: puede superar los 20 MB.',
            'archivo.extensions' => 'Tipo de archivo no admitido. Se aceptan documentos (PDF, Word, Excel, '
                                . 'PowerPoint, OpenDocument, texto), imágenes y comprimidos.',
            'archivo.mimes'    => 'Tipo de archivo no admitido. Se aceptan documentos (PDF, Word, Excel, '
                                . 'PowerPoint, OpenDocument, texto), imágenes y comprimidos.',
            'archivo.max'      => 'El archivo no puede superar los 20 MB.',
        ]);

        $archivo = $this->service->adjuntar($id, $request->file('archivo'), $data['descripcion'] ?? null);
        return response()->json(['data' => $archivo], 201);
    }

    /** GET /api/contrato-archivos/{id}/descargar */
    public function descargar(int $id): StreamedResponse|JsonResponse
    {
        $archivo = ContratoArchivo::find($id);
        if (!$archivo || !$this->service->puedeVer((int) $archivo->sector_id)) {
            return $this->noEncontrado('Archivo no encontrado.');
        }

        $disk = Storage::disk(ContratoService::ARCHIVOS_DISK);
        if (!$disk->exists($archivo->ruta)) {
            return response()->json([
                'error'   => 'file_missing',
                'message' => 'El archivo ya no está disponible en el servidor.',
            ], 410);
        }
        return $disk->download($archivo->ruta, $archivo->nombre_original);
    }

    /** DELETE /api/contrato-archivos/{id} */
    public function quitarArchivo(int $id): JsonResponse
    {
        $archivo = ContratoArchivo::find($id);
        if (!$archivo || !$this->service->puedeVer((int) $archivo->sector_id)) {
            return $this->noEncontrado('Archivo no encontrado.');
        }
        if (!$this->service->puedeEditar((int) $archivo->sector_id)) {
            return $this->sinPermiso();
        }

        $this->service->quitarArchivo($archivo);
        return response()->json(['message' => 'Archivo eliminado.']);
    }

    private function sinPermiso(): JsonResponse
    {
        return response()->json([
            'error'   => 'forbidden',
            'message' => 'La ficha del contrato la mantiene el administrador del sistema.',
        ], 403);
    }

    private function noEncontrado(string $mensaje = 'Contrato no encontrado.'): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => $mensaje,
        ], 404);
    }
}
