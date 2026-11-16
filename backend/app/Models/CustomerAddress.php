<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends BaseModel
{
    protected $table = 'customer_addresses';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'customer_id' => 'integer',
            'zone_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
