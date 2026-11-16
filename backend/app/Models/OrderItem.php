<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends BaseModel
{
    protected $table = 'order_items';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'variant_id' => 'integer',
            'product_id' => 'integer',
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'deposit_amount' => 'integer',
            'lead_time_days' => 'integer',
            'shipment_id' => 'integer',
            'arrival_id' => 'integer',
            'received_qty' => 'integer',
            'stock_reserved' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(Arrival::class, 'arrival_id');
    }
}
