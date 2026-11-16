<?php

namespace App\Enums;

enum DeliveryMode: string
{
    case HOME = 'HOME';
    case PICKUP = 'PICKUP';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
