<?php

namespace App\Models;

class NewsletterSubscriber extends BaseModel
{
    protected $table = 'newsletter_subscribers';

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
