<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        // Every test starts from an empty ledger so assertions on exact
        // positions/totals never depend on execution order or leftover data.
        static::getContainer()->get('doctrine')->getConnection()
            ->executeStatement('TRUNCATE TABLE transactions RESTART IDENTITY');
    }

    protected function createTransaction(int $userId, array $payload, ?string $idempotencyKey = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $this->client->request('POST', "/users/{$userId}/transactions", [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeResponse(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
