<?php

namespace App\Models;

class Supplier extends BaseModel
{
    protected $table = 'suppliers';

    protected function casts(): array
    {
        return [
            'usual_lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
