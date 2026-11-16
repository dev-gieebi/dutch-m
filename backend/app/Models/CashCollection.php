<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCollection extends BaseModel
{
    protected $table = 'cash_collections';

    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'order_id' => 'integer',
            'amount' => 'integer',
            'collected_by_id' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'collected_by_id');
    }
}
