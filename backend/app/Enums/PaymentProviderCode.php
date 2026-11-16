<?php

namespace App\Enums;

enum PaymentProviderCode: string
{
    case AIRTEL = 'airtel';
    case MOOV = 'moov';
    case MOCK = 'mock';
    case MANUAL = 'manual';
    case CASH = 'cash';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
