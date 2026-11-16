<?php

namespace App\Enums;

enum StockMovementType: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case RESERVE = 'RESERVE';
    case RELEASE = 'RELEASE';
    case ADJUST = 'ADJUST';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
