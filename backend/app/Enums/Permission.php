<?php

namespace App\Enums;

enum Permission: string
{
    case DASHBOARD_READ = 'dashboard.read';
    case CATALOG_READ = 'catalog.read';
    case CATALOG_WRITE = 'catalog.write';
    case PRICES_WRITE = 'prices.write';
    case STOCK_READ = 'stock.read';
    case STOCK_WRITE = 'stock.write';
    case SUPPLIERS_WRITE = 'suppliers.write';
    case ORDERS_READ = 'orders.read';
    case ORDERS_STATUS = 'orders.status';
    case ORDERS_WRITE = 'orders.write';
    case ORDERS_REFUND = 'orders.refund';
    case SHIPMENTS_WRITE = 'shipments.write';
    case ARRIVALS_WRITE = 'arrivals.write';
    case DELIVERY_WRITE = 'delivery.write';
    case DRIVER_DELIVERIES = 'driver.deliveries';
    case PAYMENTS_READ = 'payments.read';
    case PAYMENTS_MANUAL = 'payments.manual';
    case CUSTOMERS_READ = 'customers.read';
    case CUSTOMERS_WRITE = 'customers.write';
    case MESSAGES_READ = 'messages.read';
    case MESSAGES_WRITE = 'messages.write';
    case MARKETING_WRITE = 'marketing.write';
    case CONTENT_WRITE = 'content.write';
    case REVIEWS_WRITE = 'reviews.write';
    case REPORTS_READ = 'reports.read';
    case EXPORTS = 'exports';
    case SETTINGS_WRITE = 'settings.write';
    case USERS_WRITE = 'users.write';
    case AUDIT_READ = 'audit.read';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
