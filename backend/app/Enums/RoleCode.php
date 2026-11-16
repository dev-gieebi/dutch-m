<?php

namespace App\Enums;

enum RoleCode: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case GERANT = 'GERANT';
    case CATALOGUE = 'CATALOGUE';
    case SERVICE_CLIENT = 'SERVICE_CLIENT';
    case LOGISTIQUE = 'LOGISTIQUE';
    case LIVREUR = 'LIVREUR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
