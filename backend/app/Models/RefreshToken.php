<?php

namespace App\Models;

class RefreshToken extends BaseModel
{
    protected $table = 'refresh_tokens';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'admin_user_id' => 'integer',
            'customer_id' => 'integer',
        ];
    }
}
