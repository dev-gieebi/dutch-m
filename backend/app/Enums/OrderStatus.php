<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING_PAYMENT = 'PENDING_PAYMENT';
    case CONFIRMED = 'CONFIRMED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
