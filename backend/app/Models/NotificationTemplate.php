<?php

namespace App\Models;

class NotificationTemplate extends BaseModel
{
    protected $table = 'notification_templates';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
