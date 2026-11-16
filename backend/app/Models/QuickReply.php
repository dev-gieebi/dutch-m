<?php

namespace App\Models;

class QuickReply extends BaseModel
{
    protected $table = 'quick_replies';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
