<?php

namespace App\Enums;

enum AdminNotificationType: string
{
    case NEW_ORDER = 'NEW_ORDER';
    case PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';
    case NEW_MESSAGE = 'NEW_MESSAGE';
    case LOW_STOCK = 'LOW_STOCK';
    case ARRIVAL_DELAYED = 'ARRIVAL_DELAYED';
    case DUPLICATE_PAYMENT = 'DUPLICATE_PAYMENT';
    case MISSING_ITEMS = 'MISSING_ITEMS';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
