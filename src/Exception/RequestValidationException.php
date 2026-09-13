<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class RequestValidationException extends \RuntimeException
{
    public function __construct(public readonly ConstraintViolationListInterface $violations)
    {
        parent::__construct('Validation failed.');
    }
}
