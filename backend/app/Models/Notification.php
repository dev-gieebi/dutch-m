<?php

namespace App\Models;

class Notification extends BaseModel
{
    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'order_id' => 'integer',
        ];
    }
}
