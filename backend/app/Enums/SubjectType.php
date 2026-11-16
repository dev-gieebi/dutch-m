<?php

namespace App\Enums;

enum SubjectType: string
{
    case ADMIN = 'ADMIN';
    case CUSTOMER = 'CUSTOMER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
