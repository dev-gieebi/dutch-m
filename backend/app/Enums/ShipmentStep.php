<?php

namespace App\Enums;

enum ShipmentStep: string
{
    case ORDER_CONFIRMED = 'ORDER_CONFIRMED';
    case ORDERED_FROM_SUPPLIER = 'ORDERED_FROM_SUPPLIER';
    case SHIPPED_FROM_CHINA = 'SHIPPED_FROM_CHINA';
    case IN_INTERNATIONAL_TRANSIT = 'IN_INTERNATIONAL_TRANSIT';
    case ARRIVED_IN_GABON = 'ARRIVED_IN_GABON';
    case RECEIVED_IN_STOCK = 'RECEIVED_IN_STOCK';
    case PREPARING = 'PREPARING';
    case READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case DELAYED = 'DELAYED';
    case CANCELLED = 'CANCELLED';
    case RETURNED = 'RETURNED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
