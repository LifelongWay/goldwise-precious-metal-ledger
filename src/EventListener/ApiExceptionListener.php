<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\InsufficientPositionException;
use App\Exception\RequestValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException as SerializerUnexpectedValueException;

/**
 * Translates every exception raised while handling an API request into the
 * ledger's single JSON error envelope: {"error": {"code", "message", ...}}.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        [$status, $code, $message, $extra] = match (true) {
            $exception instanceof RequestValidationException => [400, 'validation_failed', 'Validation failed.', [
                'violations' => array_map(
                    static fn ($violation) => [
                        'field' => $violation->getPropertyPath(),
                        'message' => $violation->getMessage(),
                    ],
                    iterator_to_array($exception->violations),
                ),
            ]],
            $exception instanceof InsufficientPositionException => [409, 'insufficient_position', $exception->getMessage(), []],
            $exception instanceof SerializerUnexpectedValueException => [400, 'invalid_request_body', 'The request body could not be parsed.', []],
            $exception instanceof \JsonException => [400, 'invalid_request_body', 'The request body could not be parsed.', []],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), 'http_error', $exception->getMessage(), []],
            default => [500, 'internal_error', 'An unexpected error occurred.', []],
        };

        $event->setResponse(new JsonResponse([
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status));
    }
}
