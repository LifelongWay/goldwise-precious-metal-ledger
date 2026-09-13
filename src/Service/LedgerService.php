<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateTransactionRequest;
use App\Entity\Transaction;
use App\Enum\Side;
use App\Exception\InsufficientPositionException;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LedgerService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRepository $transactions,
    ) {
    }

    /**
     * Creates a transaction for a user, enforcing that a SELL can never drive
     * the position negative.
     *
     * Concurrency: before touching any data, this acquires a Postgres advisory
     * transaction lock keyed on the user id. That serializes every write for a
     * given user, so two concurrent SELL requests can't both read the same
     * "current position" and both pass validation — the second one only starts
     * reading once the first has committed (or rolled back). The lock is
     * released automatically when the transaction ends.
     *
     * Idempotency: if the caller supplies an idempotency key that was already
     * used for this user, the original transaction is returned instead of
     * creating a duplicate.
     */
    public function createTransaction(int $userId, CreateTransactionRequest $request, ?string $idempotencyKey): Transaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($userId, $request, $idempotencyKey): Transaction {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(:userId)',
                ['userId' => $userId],
            );

            if ($idempotencyKey !== null) {
                $existing = $this->transactions->findOneBy([
                    'userId' => $userId,
                    'idempotencyKey' => $idempotencyKey,
                ]);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $metal = $request->metal();
            $side = $request->side();
            $quantity = $request->quantity();

            if ($side === Side::SELL) {
                $position = $this->transactions->sumPosition($userId, $metal);

                if (bccomp($quantity, $position, 8) > 0) {
                    throw new InsufficientPositionException($metal, $position, $quantity);
                }
            }

            $transaction = new Transaction(
                $userId,
                $metal,
                $side,
                $quantity,
                $request->price(),
                $request->currency(),
                $idempotencyKey,
            );

            $this->transactions->save($transaction);

            return $transaction;
        });
    }

    /**
     * @return array<int, array{metal: string, quantity: string}>
     */
    public function getPositions(int $userId): array
    {
        return $this->transactions->findPositionsByUser($userId);
    }
}
