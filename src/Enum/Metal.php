<?php

declare(strict_types=1);

namespace App\Enum;

enum Metal: string
{
    use HasValues;

    case GOLD = 'GOLD';
    case SILVER = 'SILVER';
}
