<?php

namespace App\Enums;

enum SenderType: string
{
    case CUSTOMER = 'CUSTOMER';
    case ADMIN = 'ADMIN';
    case SYSTEM = 'SYSTEM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
