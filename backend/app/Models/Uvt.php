<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Uvt extends Model
{
    protected $table      = 'uvt';
    protected $primaryKey = 'uvt_id';
    public $timestamps    = false;

    protected $fillable = ['siglas', 'nombre', 'responsable'];

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'uvt_id', 'uvt_id');
    }
}
