<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Currency;
use App\Enum\Metal;
use App\Enum\Side;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateTransactionRequest
{
    #[Assert\NotBlank(message: 'metal is required.')]
    #[Assert\Choice(callback: [Metal::class, 'values'], message: 'metal must be one of: {{ choices }}.')]
    public string $metal = '';

    #[Assert\NotBlank(message: 'side is required.')]
    #[Assert\Choice(callback: [Side::class, 'values'], message: 'side must be one of: {{ choices }}.')]
    public string $side = '';

    #[Assert\NotNull(message: 'quantity is required.')]
    #[Assert\Type(type: 'numeric', message: 'quantity must be a number.')]
    #[Assert\Positive(message: 'quantity must be greater than zero.')]
    public mixed $quantity = null;

    #[Assert\NotNull(message: 'price is required.')]
    #[Assert\Type(type: 'numeric', message: 'price must be a number.')]
    #[Assert\Positive(message: 'price must be greater than zero.')]
    public mixed $price = null;

    #[Assert\NotBlank(message: 'currency is required.')]
    #[Assert\Choice(callback: [Currency::class, 'values'], message: 'currency must be one of: {{ choices }}.')]
    public string $currency = '';

    public function metal(): Metal
    {
        return Metal::from($this->metal);
    }

    public function side(): Side
    {
        return Side::from($this->side);
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency);
    }

    public function quantity(): string
    {
        return self::toDecimalString($this->quantity);
    }

    public function price(): string
    {
        return self::toDecimalString($this->price);
    }

    /**
     * Normalizes to a fixed-scale decimal string without ever routing a
     * plain decimal value through a PHP float. JSON has no exact decimal
     * type, so a bare JSON number (e.g. `"quantity": 2.5`) is unavoidably
     * parsed into a float before this class ever sees it — but a caller
     * that sends the value as a JSON string instead (e.g. `"2.5"`) gets an
     * exact, float-free path straight through bcmath.
     */
    private static function toDecimalString(int|float|string $value): string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', 8);
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return bcadd($value, '0', 8);
        }

        // A bare JSON number (float) or an unusual numeric string (e.g.
        // scientific notation) bcmath won't parse directly. PHP's default
        // float-to-string cast (precision=14) recovers the intended
        // decimal for any realistic magnitude.
        return bcadd((string) (float) $value, '0', 8);
    }
}
