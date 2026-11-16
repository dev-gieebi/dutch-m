<?php

namespace App\Enums;

enum NotificationTemplateCode: string
{
    case ORDER_CREATED = 'ORDER_CREATED';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case SHIPMENT_STEP = 'SHIPMENT_STEP';
    case SHIPMENT_DELAYED = 'SHIPMENT_DELAYED';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case ORDER_DELIVERED = 'ORDER_DELIVERED';
    case BACK_IN_STOCK = 'BACK_IN_STOCK';
    case CONVERSATION_REPLY = 'CONVERSATION_REPLY';
    case PASSWORD_RESET = 'PASSWORD_RESET';
    case PHONE_VERIFICATION = 'PHONE_VERIFICATION';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
