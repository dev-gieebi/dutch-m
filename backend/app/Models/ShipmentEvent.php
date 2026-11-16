<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvent extends BaseModel
{
    protected $table = 'shipment_events';

    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'admin_user_id' => 'integer',
            'arrival_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
