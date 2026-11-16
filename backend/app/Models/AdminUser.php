<?php

namespace App\Models;

use App\Casts\SimpleArray;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminUser extends BaseModel
{
    use SoftDeletes;

    protected $table = 'admin_users';

    protected $hidden = ['password_hash', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_attempts' => 'integer',
            'role_codes' => SimpleArray::class,
        ];
    }
}
