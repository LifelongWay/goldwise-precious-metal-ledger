<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the primitive LedgerService relies on to stop two concurrent SELLs
 * from jointly overselling: pg_advisory_xact_lock(user_id) serializes writes
 * for the same user across separate database connections (i.e. separate
 * requests/processes), and the lock is released when the holding transaction
 * ends.
 */
final class AdvisoryLockConcurrencyTest extends KernelTestCase
{
    public function testSecondConnectionCannotAcquireLockUntilFirstReleasesIt(): void
    {
        self::bootKernel();

        $conn1 = static::getContainer()->get('doctrine')->getConnection();
        $conn2 = DriverManager::getConnection($conn1->getParams());

        $lockKey = 918273645; // arbitrary id standing in for a "user id"

        try {
            $conn1->beginTransaction();
            $conn1->executeStatement('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            $conn2->beginTransaction();
            $acquiredWhileHeld = (bool) $conn2->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [$lockKey]);
            $conn2->rollBack();

            self::assertFalse($acquiredWhileHeld, 'A second connection must not acquire the lock while the first still holds it.');

            $conn1->commit(); // releases conn1's advisory lock

            $conn2->beginTransaction();
            $acquiredAfterRelease = (bool) $conn2->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [$lockKey]);
            $conn2->rollBack();

            self::assertTrue($acquiredAfterRelease, 'The lock must become available once the holder commits.');
        } finally {
            $conn2->close();
        }
    }
}
