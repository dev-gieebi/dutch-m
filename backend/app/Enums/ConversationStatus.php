<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case NEW = 'NEW';
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
