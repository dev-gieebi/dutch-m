<?php

namespace App\Enums;

enum AvailabilityMode: string
{
    case IN_STOCK = 'IN_STOCK';
    case PREORDER = 'PREORDER';
    case UNAVAILABLE = 'UNAVAILABLE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
