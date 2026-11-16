<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends BaseModel
{
    protected $table = 'product_variants';

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'promo_price' => 'integer',
            'promo_starts_at' => 'datetime',
            'promo_ends_at' => 'datetime',
            'cost_price' => 'integer',
            'stock_on_hand' => 'integer',
            'stock_reserved' => 'integer',
            'low_stock_threshold' => 'integer',
            'auto_switched' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values', 'variant_id', 'attribute_value_id');
    }

    /** Stock disponible (physique - réservé). */
    public function stockAvailable(): int
    {
        return (int) $this->stock_on_hand - (int) $this->stock_reserved;
    }
}
