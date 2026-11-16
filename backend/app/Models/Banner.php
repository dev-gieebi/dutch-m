<?php

namespace App\Models;

class Banner extends BaseModel
{
    protected $table = 'banners';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
