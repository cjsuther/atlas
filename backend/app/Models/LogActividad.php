<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una actividad registrada: quién hizo qué, cuándo y con qué observación.
 *
 * Por ahora registra la aprobación de los movimientos: lo que se envía a
 * aprobar, lo que se aprueba y lo que se rechaza. `cuenta_operativa_id` es la
 * cuenta del movimiento y recorta el log al alcance de quien lo consulta.
 */
class LogActividad extends Model
{
    /** Lo cargado, modificado o dado de baja que queda esperando aprobación. */
    public const ALTA                 = 'alta';
    public const MODIFICACION         = 'modificacion';
    public const REENVIO              = 'reenvio';
    public const BAJA                 = 'baja';
    public const PEDIDO_MODIFICACION  = 'pedido_modificacion';
    public const PEDIDO_BAJA          = 'pedido_baja';

    /** La decisión del aprobador. */
    public const APROBACION           = 'aprobacion';
    public const RECHAZO              = 'rechazo';

    /** Nombre de cada acción en pantalla. */
    public const ETIQUETAS = [
        self::ALTA                => 'Carga (sin aprobar)',
        self::MODIFICACION        => 'Modificación (sin aprobar)',
        self::REENVIO             => 'Reenvío a aprobar',
        self::BAJA                => 'Baja de un movimiento sin aprobar',
        self::PEDIDO_MODIFICACION => 'Pedido de modificación',
        self::PEDIDO_BAJA         => 'Pedido de baja',
        self::APROBACION          => 'Aprobación',
        self::RECHAZO             => 'Rechazo',
    ];

    protected $table   = 'log_actividades';
    public $timestamps = false;

    protected $fillable = [
        'fecha', 'usuario', 'accion', 'tabla', 'registro_id',
        'cuenta_operativa_id', 'descripcion', 'observacion',
    ];

    protected $casts = [
        'fecha' => 'datetime',
    ];
}
