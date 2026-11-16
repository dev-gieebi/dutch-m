<?php

namespace App\Enums;

enum OrderSource: string
{
    case WEB = 'WEB';
    case ADMIN = 'ADMIN';
    case WHATSAPP = 'WHATSAPP';
    case PHONE = 'PHONE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
