<?php

namespace App\Enums;

enum PaymentKind: string
{
    case DEPOSIT = 'DEPOSIT';
    case BALANCE = 'BALANCE';
    case FULL = 'FULL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
