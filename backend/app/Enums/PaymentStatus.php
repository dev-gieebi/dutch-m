<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case AWAITING_CASH = 'AWAITING_CASH';
    case PENDING = 'PENDING';
    case DEPOSIT_PAID = 'DEPOSIT_PAID';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    case REFUNDED = 'REFUNDED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
