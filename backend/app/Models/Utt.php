<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Utt extends Model
{
    protected $table      = 'utt';
    protected $primaryKey = 'utt_id';
    public $timestamps    = false;

    protected $fillable = ['denominacion', 'nombre'];
}
