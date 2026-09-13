<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Enum\Metal;
use App\Enum\Side;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable record of a single BUY/SELL order. Once persisted, a transaction
 * is never updated or deleted — corrections happen by recording an offsetting
 * transaction, not by editing history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['user_id', 'metal'], name: 'idx_transactions_user_metal')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_transactions_user_created_at')]
#[ORM\UniqueConstraint(name: 'uniq_transactions_user_idempotency_key', columns: ['user_id', 'idempotency_key'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(name: 'user_id', type: 'bigint')]
    private readonly string $userId;

    #[ORM\Column(type: 'string', length: 16, enumType: Metal::class)]
    private readonly Metal $metal;

    #[ORM\Column(type: 'string', length: 4, enumType: Side::class)]
    private readonly Side $side;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private readonly string $quantity;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private readonly string $price;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class)]
    private readonly Currency $currency;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 255, nullable: true)]
    private readonly ?string $idempotencyKey;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        int|string $userId,
        Metal $metal,
        Side $side,
        string $quantity,
        string $price,
        Currency $currency,
        ?string $idempotencyKey = null,
    ) {
        $this->userId = (string) $userId;
        $this->metal = $metal;
        $this->side = $side;
        $this->quantity = $quantity;
        $this->price = $price;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getUserId(): int
    {
        return (int) $this->userId;
    }

    public function getMetal(): Metal
    {
        return $this->metal;
    }

    public function getSide(): Side
    {
        return $this->side;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
