<?php

namespace App\Enums;

enum AttributeType: string
{
    case COLOR = 'COLOR';
    case MATERIAL = 'MATERIAL';
    case SIZE = 'SIZE';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
