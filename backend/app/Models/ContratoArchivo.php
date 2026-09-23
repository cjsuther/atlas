<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Archivo adjunto a un contrato. Cuelga del nodo del contrato (`sector_id`);
 * el archivo en sí está en el disco privado del backend, en `ruta`, que nunca
 * sale por la API.
 */
class ContratoArchivo extends Model
{
    protected $table = 'contrato_archivos';

    protected $fillable = [
        'sector_id',
        'nombre_original',
        'ruta',
        'mime',
        'tamano',
        'descripcion',
        'subido_por',
    ];

    protected $hidden = ['ruta'];

    protected $casts = [
        'tamano' => 'integer',
    ];

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }
}
