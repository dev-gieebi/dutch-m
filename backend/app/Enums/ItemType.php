<?php

namespace App\Enums;

enum ItemType: string
{
    case LOCAL = 'LOCAL';
    case PREORDER = 'PREORDER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
