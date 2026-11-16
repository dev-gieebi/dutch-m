<?php

namespace App\Models;

class Setting extends BaseModel
{
    protected $table = 'settings';

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
