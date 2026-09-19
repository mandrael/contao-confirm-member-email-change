<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron\ResendEmailChangeAnchorNoticeCron;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use PHPUnit\Framework\TestCase;

/**
 * Codex 6: a lost notice must not cost the member their only way back. A NEW token is
 * issued for the same deadline - the window is never extended.
 */
class ResendEmailChangeAnchorNoticeCronTest extends TestCase
{
    public function testIssuesANewTokenForTheSameDeadlineAndSendsIt(): void
    {
        $oldHash = str_repeat('a', 64);

        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($oldHash): array {
                self::assertStringContainsString('emailChangeAnchorNotified = 0', $sql);
                self::assertStringContainsString('emailChangeAnchorExpires > ?', $sql);

                return [['id' => 7, 'emailChangeAnchorHash' => $oldHash, 'emailChangeAnchorEmail' => 'old@example.com']];
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        $sent = [];
        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::once())->method('send')->willReturnCallback(
            static function (int $memberId, string $to, string $token) use (&$sent): bool {
                $sent = [$memberId, $to, $token];

                return true;
            },
        );

        (new ResendEmailChangeAnchorNoticeCron($connection, $anchorNotice))();

        self::assertCount(1, $statements);
        self::assertStringNotContainsString('emailChangeAnchorExpires', $statements[0][0], 'the deadline must not be touched');
        self::assertSame(hash('sha256', $sent[2]), $statements[0][1][0]);
        self::assertSame($oldHash, $statements[0][1][2], 'the update is conditional on the hash that was read');
        self::assertSame([7, 'old@example.com'], [$sent[0], $sent[1]]);
    }

    /**
     * The anchor changed between the read and the update (second confirmed change, a
     * revoke, a parallel run) - do not overwrite a link that may already be on its way.
     */
    public function testSkipsWhenTheAnchorChangedInParallel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 7, 'emailChangeAnchorHash' => str_repeat('a', 64), 'emailChangeAnchorEmail' => 'old@example.com'],
        ]);
        $connection->method('executeStatement')->willReturn(0);

        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::never())->method('send');

        (new ResendEmailChangeAnchorNoticeCron($connection, $anchorNotice))();
    }
}
