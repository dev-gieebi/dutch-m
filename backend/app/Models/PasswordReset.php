<?php

namespace App\Models;

class PasswordReset extends BaseModel
{
    protected $table = 'password_resets';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
            'customer_id' => 'integer',
            'admin_user_id' => 'integer',
        ];
    }
}
