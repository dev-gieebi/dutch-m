<?php

namespace App\Models;

class Page extends BaseModel
{
    protected $table = 'pages';

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }
}
