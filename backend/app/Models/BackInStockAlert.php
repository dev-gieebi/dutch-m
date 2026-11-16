<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackInStockAlert extends BaseModel
{
    protected $table = 'back_in_stock_alerts';

    protected function casts(): array
    {
        return [
            'variant_id' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
