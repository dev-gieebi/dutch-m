<?php

namespace App\Enums;

enum TokenPurpose: string
{
    case RESET_PASSWORD = 'RESET_PASSWORD';
    case VERIFY_PHONE = 'VERIFY_PHONE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
