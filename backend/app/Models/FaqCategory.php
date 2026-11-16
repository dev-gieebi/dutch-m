<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FaqCategory extends BaseModel
{
    protected $table = 'faq_categories';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class, 'category_id');
    }
}
