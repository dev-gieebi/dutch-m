<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrivalEvent extends BaseModel
{
    protected $table = 'arrival_events';

    protected function casts(): array
    {
        return [
            'arrival_id' => 'integer',
            'admin_user_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(Arrival::class, 'arrival_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
