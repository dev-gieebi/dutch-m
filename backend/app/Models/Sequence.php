<?php

namespace App\Models;

class Sequence extends BaseModel
{
    protected $table = 'sequences';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'value' => 'integer',
        ];
    }
}
