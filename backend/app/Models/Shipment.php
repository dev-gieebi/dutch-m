<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends BaseModel
{
    protected $table = 'shipments';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'estimated_delivery_date' => 'datetime',
            'shipping_fee' => 'integer',
            'driver_id' => 'integer',
            'scheduled_for' => 'datetime',
            'delivered_at' => 'datetime',
            'is_delayed' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'shipment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class, 'shipment_id');
    }
}
