<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends BaseModel
{
    use SoftDeletes;

    protected $table = 'customers';

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'registered_at' => 'datetime',
            'last_login_at' => 'datetime',
            'newsletter_consent' => 'boolean',
            'newsletter_consent_at' => 'datetime',
            'is_guest' => 'boolean',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
}
