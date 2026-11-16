<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends BaseModel
{
    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'admin_user_id' => 'integer',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
