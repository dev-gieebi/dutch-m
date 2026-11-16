<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends BaseModel
{
    protected $table = 'stock_movements';

    protected function casts(): array
    {
        return [
            'variant_id' => 'integer',
            'quantity' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'order_id' => 'integer',
            'arrival_id' => 'integer',
            'admin_user_id' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
