<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Deliberately small, fixed set of supported settlement currencies.
 * Not a live currency/FX list — this is a ledger, not a payment processor.
 */
enum Currency: string
{
    use HasValues;

    case GBP = 'GBP';
    case USD = 'USD';
    case EUR = 'EUR';
}
