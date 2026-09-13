<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class TransactionApiTest extends ApiTestCase
{
    private const USER = 1;

    public function testBuyIncreasesPosition(): void
    {
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 5, 'price' => 3200, 'currency' => 'GBP']);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame([['metal' => 'GOLD', 'quantity' => '5.00000000']], $this->decodeResponse());
    }

    public function testQuantityAndPriceAcceptStringDecimalsWithoutFloatRounding(): void
    {
        // 0.1 + 0.2 is the textbook case where float arithmetic goes wrong
        // (0.30000000000000004). Sending both legs as JSON strings must
        // never let a float touch the value, so the sum comes out exact.
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => '0.1', 'price' => '100', 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => '0.2', 'price' => '100', 'currency' => 'GBP']);

        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame([['metal' => 'GOLD', 'quantity' => '0.30000000']], $this->decodeResponse());
    }

    public function testSellDecreasesPosition(): void
    {
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 5, 'price' => 3200, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'SELL', 'quantity' => 2, 'price' => 3210, 'currency' => 'GBP']);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame([['metal' => 'GOLD', 'quantity' => '3.00000000']], $this->decodeResponse());
    }

    public function testMultipleTransactionsAreCalculatedCorrectly(): void
    {
        // BUY 5, SELL 1.5, BUY 0.75 -> 4.25, matching the spec's worked example.
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 5, 'price' => 3200, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'SELL', 'quantity' => 1.5, 'price' => 3210, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 0.75, 'price' => 3205, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'SILVER', 'side' => 'BUY', 'quantity' => 120, 'price' => 40, 'currency' => 'GBP']);

        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame(
            [
                ['metal' => 'GOLD', 'quantity' => '4.25000000'],
                ['metal' => 'SILVER', 'quantity' => '120.00000000'],
            ],
            $this->decodeResponse(),
        );
    }

    public function testSellExceedingPositionIsRejected(): void
    {
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 2, 'price' => 3200, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'SELL', 'quantity' => 2.5, 'price' => 3210, 'currency' => 'GBP']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('insufficient_position', $this->decodeResponse()['error']['code']);

        // The rejected SELL must not have been recorded.
        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame([['metal' => 'GOLD', 'quantity' => '2.00000000']], $this->decodeResponse());
    }

    public function testSellingWithNoPriorPositionIsRejected(): void
    {
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'SELL', 'quantity' => 1, 'price' => 3200, 'currency' => 'GBP']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidInputIsRejected(array $payload, string $expectedField): void
    {
        $this->createTransaction(self::USER, $payload);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $body = $this->decodeResponse();
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertContains($expectedField, array_column($body['error']['violations'], 'field'));
    }

    public static function invalidPayloadProvider(): iterable
    {
        yield 'zero quantity' => [['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 0, 'price' => 100, 'currency' => 'GBP'], 'quantity'];
        yield 'negative quantity' => [['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => -1, 'price' => 100, 'currency' => 'GBP'], 'quantity'];
        yield 'zero price' => [['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 1, 'price' => 0, 'currency' => 'GBP'], 'price'];
        yield 'negative price' => [['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 1, 'price' => -5, 'currency' => 'GBP'], 'price'];
        yield 'unsupported metal' => [['metal' => 'PLATINUM', 'side' => 'BUY', 'quantity' => 1, 'price' => 100, 'currency' => 'GBP'], 'metal'];
        yield 'unsupported side' => [['metal' => 'GOLD', 'side' => 'HOLD', 'quantity' => 1, 'price' => 100, 'currency' => 'GBP'], 'side'];
        yield 'unsupported currency' => [['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 1, 'price' => 100, 'currency' => 'XYZ'], 'currency'];
        yield 'missing metal' => [['side' => 'BUY', 'quantity' => 1, 'price' => 100, 'currency' => 'GBP'], 'metal'];
    }

    public function testHistoryCanBeFilteredByMetal(): void
    {
        $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 1, 'price' => 100, 'currency' => 'GBP']);
        $this->createTransaction(self::USER, ['metal' => 'SILVER', 'side' => 'BUY', 'quantity' => 10, 'price' => 40, 'currency' => 'GBP']);

        $this->client->request('GET', '/users/'.self::USER.'/transactions?metal=GOLD');
        $body = $this->decodeResponse();

        self::assertSame(1, $body['meta']['total']);
        self::assertSame('GOLD', $body['data'][0]['metal']);
    }

    public function testHistoryIsPaginated(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createTransaction(self::USER, ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 1, 'price' => 100, 'currency' => 'GBP']);
        }

        $this->client->request('GET', '/users/'.self::USER.'/transactions?page=2&limit=2');
        $body = $this->decodeResponse();

        self::assertSame(5, $body['meta']['total']);
        self::assertSame(2, $body['meta']['page']);
        self::assertCount(2, $body['data']);
    }

    public function testIdempotencyKeyPreventsDuplicateTransactions(): void
    {
        $payload = ['metal' => 'GOLD', 'side' => 'BUY', 'quantity' => 5, 'price' => 3200, 'currency' => 'GBP'];

        $this->createTransaction(self::USER, $payload, 'retry-key-1');
        $first = $this->decodeResponse();

        $this->createTransaction(self::USER, $payload, 'retry-key-1');
        $second = $this->decodeResponse();

        self::assertSame($first['id'], $second['id']);

        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame([['metal' => 'GOLD', 'quantity' => '5.00000000']], $this->decodeResponse());
    }

    public function testUserWithNoTransactionsHasEmptyPositionsAndHistory(): void
    {
        $this->client->request('GET', '/users/'.self::USER.'/positions');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->decodeResponse());

        $this->client->request('GET', '/users/'.self::USER.'/transactions');
        $body = $this->decodeResponse();
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    public function testMalformedJsonBodyIsRejected(): void
    {
        $this->client->request('POST', '/users/'.self::USER.'/transactions', [], [], ['CONTENT_TYPE' => 'application/json'], '{not json');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
}
