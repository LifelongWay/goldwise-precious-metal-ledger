<?php

declare(strict_types=1);

namespace App\Enum;

enum Side: string
{
    use HasValues;

    case BUY = 'BUY';
    case SELL = 'SELL';
}
