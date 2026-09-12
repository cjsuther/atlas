<?php

namespace App\Models;

use App\Support\SectorTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Permiso de un usuario sobre una rama del árbol de la estructura.
 *
 * `sector_id` en null es la raíz: toda la organización. El nivel se hereda
 * hacia abajo —lo que se concede sobre un nodo vale para todo lo que cuelga de
 * él— y la escritura incluye la lectura.
 */
class UsuarioPermiso extends Model
{
    public const NIVEL_LECTURA   = 'lectura';
    public const NIVEL_ESCRITURA = 'escritura';

    public const NIVELES = [self::NIVEL_LECTURA, self::NIVEL_ESCRITURA];

    protected $table = 'usuario_permisos';

    protected $fillable = ['user_role_id', 'sector_id', 'nivel'];

    protected $appends = ['ruta'];

    public function usuario()
    {
        return $this->belongsTo(UserRole::class, 'user_role_id');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id', 'sector_id');
    }

    public function esEscritura(): bool
    {
        return $this->nivel === self::NIVEL_ESCRITURA;
    }

    /** Camino del nodo en el árbol, para mostrar en pantalla. */
    public function getRutaAttribute(): string
    {
        return app(SectorTree::class)->rutaDe(
            $this->sector_id !== null ? (int) $this->sector_id : null
        );
    }
}
