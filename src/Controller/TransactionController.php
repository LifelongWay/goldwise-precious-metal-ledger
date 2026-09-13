<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateTransactionRequest;
use App\Entity\Transaction;
use App\Enum\Metal;
use App\Enum\Side;
use App\Exception\RequestValidationException;
use App\Repository\TransactionRepository;
use App\Service\LedgerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TransactionController
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransactionRepository $transactions,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/users/{userId}/transactions', name: 'transactions_create', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function create(int $userId, Request $request): JsonResponse
    {
        /** @var CreateTransactionRequest $dto */
        $dto = $this->serializer->deserialize($request->getContent(), CreateTransactionRequest::class, 'json');

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new RequestValidationException($violations);
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        $transaction = $this->ledger->createTransaction($userId, $dto, $idempotencyKey);

        return new JsonResponse(
            self::serializeTransaction($transaction),
            201,
            ['Location' => sprintf('/users/%d/transactions/%d', $userId, $transaction->getId())],
        );
    }

    #[Route('/users/{userId}/transactions', name: 'transactions_list', requirements: ['userId' => '\d+'], methods: ['GET'])]
    public function list(int $userId, Request $request): JsonResponse
    {
        $metal = $this->parseEnumQueryParam($request, 'metal', Metal::class);
        $side = $this->parseEnumQueryParam($request, 'side', Side::class);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));

        $result = $this->transactions->findHistory($userId, $metal, $side, $page, $limit);

        return new JsonResponse([
            'data' => array_map(self::serializeTransaction(...), $result['items']),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
            ],
        ]);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return T|null
     */
    private function parseEnumQueryParam(Request $request, string $name, string $enumClass): ?\BackedEnum
    {
        $value = $request->query->get($name);
        if ($value === null || $value === '') {
            return null;
        }

        $enum = $enumClass::tryFrom(strtoupper($value));
        if ($enum === null) {
            throw new BadRequestHttpException(
                sprintf('%s must be one of: %s.', $name, implode(', ', $enumClass::values())),
            );
        }

        return $enum;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeTransaction(Transaction $transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'userId' => $transaction->getUserId(),
            'metal' => $transaction->getMetal()->value,
            'side' => $transaction->getSide()->value,
            'quantity' => $transaction->getQuantity(),
            'price' => $transaction->getPrice(),
            'currency' => $transaction->getCurrency()->value,
            'timestamp' => $transaction->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
