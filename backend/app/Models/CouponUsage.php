<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponUsage extends BaseModel
{
    protected $table = 'coupon_usages';

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'order_id' => 'integer',
            'customer_id' => 'integer',
            'discount' => 'integer',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
