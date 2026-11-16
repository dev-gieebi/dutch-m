<?php

namespace App\Enums;

enum CouponType: string
{
    case PERCENT = 'PERCENT';
    case FIXED = 'FIXED';
    case FREE_SHIPPING = 'FREE_SHIPPING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
