<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
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
}
