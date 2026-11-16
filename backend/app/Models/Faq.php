<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Faq extends BaseModel
{
    protected $table = 'faqs';

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'category_id');
    }
}
