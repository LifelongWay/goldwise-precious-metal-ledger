<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\Metal;
use App\Enum\Side;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
final class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function save(Transaction $transaction): void
    {
        $this->getEntityManager()->persist($transaction);
        $this->getEntityManager()->flush();
    }

    /**
     * Net position for one user/metal, computed straight from the ledger.
     * Postgres NUMERIC arithmetic is exact, so this never loses precision.
     */
    public function sumPosition(int $userId, Metal $metal): string
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(CASE WHEN side = 'BUY' THEN quantity ELSE -quantity END), 0)::text
                FROM transactions
                WHERE user_id = :userId AND metal = :metal
                SQL,
            ['userId' => $userId, 'metal' => $metal->value],
        );

        return (string) $result;
    }

    /**
     * @return array<int, array{metal: string, quantity: string}>
     */
    public function findPositionsByUser(int $userId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT metal, COALESCE(SUM(CASE WHEN side = 'BUY' THEN quantity ELSE -quantity END), 0)::text AS quantity
                FROM transactions
                WHERE user_id = :userId
                GROUP BY metal
                ORDER BY metal ASC
                SQL,
            ['userId' => $userId],
        );

        return array_map(
            static fn (array $row): array => ['metal' => $row['metal'], 'quantity' => $row['quantity']],
            $rows,
        );
    }

    /**
     * @return array{items: list<Transaction>, total: int}
     */
    public function findHistory(int $userId, ?Metal $metal, ?Side $side, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.userId = :userId')
            ->setParameter('userId', $userId);

        if ($metal !== null) {
            $qb->andWhere('t.metal = :metal')->setParameter('metal', $metal);
        }

        if ($side !== null) {
            $qb->andWhere('t.side = :side')->setParameter('side', $side);
        }

        $total = (int) (clone $qb)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->select('t')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
