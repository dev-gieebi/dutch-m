<?php

namespace App\Models;

class DeliveryZone extends BaseModel
{
    protected $table = 'delivery_zones';

    protected function casts(): array
    {
        return [
            'fee' => 'integer',
            'estimated_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
