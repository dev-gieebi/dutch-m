<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends BaseModel
{
    protected $table = 'payment_events';

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'raw_payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
