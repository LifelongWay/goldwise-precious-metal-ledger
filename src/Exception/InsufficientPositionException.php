<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Metal;

final class InsufficientPositionException extends \RuntimeException
{
    public function __construct(
        public readonly Metal $metal,
        public readonly string $currentPosition,
        public readonly string $requestedQuantity,
    ) {
        parent::__construct(sprintf(
            'Cannot sell %s %s: current position is only %s.',
            $requestedQuantity,
            $metal->value,
            $currentPosition,
        ));
    }
}
