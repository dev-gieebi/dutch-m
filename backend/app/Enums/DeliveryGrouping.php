<?php

namespace App\Enums;

enum DeliveryGrouping: string
{
    case TOGETHER = 'TOGETHER';
    case SPLIT = 'SPLIT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
