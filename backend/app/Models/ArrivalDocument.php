<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrivalDocument extends BaseModel
{
    protected $table = 'arrival_documents';

    protected function casts(): array
    {
        return [
            'arrival_id' => 'integer',
        ];
    }

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(Arrival::class, 'arrival_id');
    }
}
