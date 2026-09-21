<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron\PurgeExpiredEmailChangeAnchorsCron;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A8: the daily cleanup for anchors nobody ever clicked - one indexed UPDATE, no
 * per-member model loop.
 */
class PurgeExpiredEmailChangeAnchorsCronTest extends TestCase
{
    public function testClearsExpiredAnchorsAndLogsWhenSomethingWasPurged(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::logicalAnd(self::stringContains('emailChangeAnchorHash'), self::stringContains('emailChangeAnchorPending')), self::isArray())
            ->willReturn(3)
        ;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('3'));

        (new PurgeExpiredEmailChangeAnchorsCron($connection, $logger))();
    }

    public function testStaysQuietWhenNothingWasPurged(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        (new PurgeExpiredEmailChangeAnchorsCron($connection, $logger))();
    }

    /**
     * Against a real (in-memory SQLite) connection rather than a mock that merely
     * echoes back what a test tells it: only the row whose expiry lies in the past
     * gets cleared, a still-valid anchor and a member with none are untouched.
     */
    public function testOnlyClearsTheExpiredAnchorAgainstARealConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE tl_member (id INTEGER, emailChangeAnchorHash TEXT, emailChangeAnchorEmail TEXT, emailChangeAnchorExpires INTEGER, emailChangeAnchorNotified INTEGER, emailChangeAnchorPending TEXT)',
        );

        $now = time();

        // Expired: hash set, expiry in the past.
        $connection->insert('tl_member', ['id' => 1, 'emailChangeAnchorHash' => str_repeat('a', 64), 'emailChangeAnchorEmail' => 'old1@example.com', 'emailChangeAnchorExpires' => $now - 10, 'emailChangeAnchorNotified' => 1, 'emailChangeAnchorPending' => '']);
        // Still valid: hash set, expiry in the future.
        $connection->insert('tl_member', ['id' => 2, 'emailChangeAnchorHash' => str_repeat('b', 64), 'emailChangeAnchorEmail' => 'old2@example.com', 'emailChangeAnchorExpires' => $now + 3600, 'emailChangeAnchorNotified' => 1, 'emailChangeAnchorPending' => '']);
        // No anchor at all.
        $connection->insert('tl_member', ['id' => 3, 'emailChangeAnchorHash' => '', 'emailChangeAnchorEmail' => '', 'emailChangeAnchorExpires' => 0, 'emailChangeAnchorNotified' => 0, 'emailChangeAnchorPending' => '']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1'));

        (new PurgeExpiredEmailChangeAnchorsCron($connection, $logger))();

        $rows = $connection->fetchAllAssociativeIndexed('SELECT * FROM tl_member ORDER BY id');

        self::assertSame('', $rows[1]['emailChangeAnchorHash'], 'the expired anchor must be cleared');
        self::assertSame(0, (int) $rows[1]['emailChangeAnchorExpires']);

        self::assertSame(str_repeat('b', 64), $rows[2]['emailChangeAnchorHash'], 'a still-valid anchor must survive');
        self::assertSame($now + 3600, (int) $rows[2]['emailChangeAnchorExpires']);

        self::assertSame('', $rows[3]['emailChangeAnchorHash'], 'a member without an anchor stays untouched (and empty)');
    }
}
