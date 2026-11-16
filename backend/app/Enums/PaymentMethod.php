<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH_ON_DELIVERY = 'CASH_ON_DELIVERY';
    case AIRTEL_MONEY = 'AIRTEL_MONEY';
    case MOOV_MONEY = 'MOOV_MONEY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
