<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arrival extends BaseModel
{
    protected $table = 'arrivals';

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'shipped_at' => 'datetime',
            'estimated_arrival_at' => 'datetime',
            'arrived_at' => 'datetime',
            'received_at' => 'datetime',
            'transport_cost' => 'integer',
            'customs_cost' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'arrival_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ArrivalEvent::class, 'arrival_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArrivalDocument::class, 'arrival_id');
    }
}
