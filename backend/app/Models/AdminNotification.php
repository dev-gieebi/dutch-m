<?php

namespace App\Models;

class AdminNotification extends BaseModel
{
    protected $table = 'admin_notifications';

    protected function casts(): array
    {
        return [
            'admin_user_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }
}
