<?php

namespace App\Services;

use App\Models\Utt;

class UttService extends BaseCrudService
{
    protected string $modelClass = Utt::class;
    protected array $searchableFields = ['denominacion', 'nombre'];
}
