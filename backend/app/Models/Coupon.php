<?php

namespace App\Models;

use App\Casts\SimpleJson;

class Coupon extends BaseModel
{
    protected $table = 'coupons';

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'max_discount' => 'integer',
            'category_ids' => SimpleJson::class,
            'product_ids' => SimpleJson::class,
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
