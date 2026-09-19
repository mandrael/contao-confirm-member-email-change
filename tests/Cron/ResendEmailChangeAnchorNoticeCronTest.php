<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron\ResendEmailChangeAnchorNoticeCron;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use PHPUnit\Framework\TestCase;

/**
 * Runde 2, Befund 2: the cron used to issue a NEW token on every retry, which could
 * invalidate a link that had already reached the mailbox. It now never rotates a valid
 * anchor - it re-sends the exact plaintext stashed in emailChangeAnchorPending while the
 * send is still pending. A duplicate send of the same link is accepted as harmless.
 */
class ResendEmailChangeAnchorNoticeCronTest extends TestCase
{
    public function testResendsTheStashedPlaintextWithoutIssuingANewToken(): void
    {
        $pending = str_repeat('b', 64);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($pending): array {
                self::assertStringContainsString('emailChangeAnchorNotified = 0', $sql);
                self::assertStringContainsString("emailChangeAnchorPending != ''", $sql);
                self::assertStringContainsString('emailChangeAnchorExpires > ?', $sql);

                return [['id' => 7, 'emailChangeAnchorEmail' => 'old@example.com', 'emailChangeAnchorPending' => $pending]];
            },
        );

        // The cron itself must never write - AnchorNotice::send() owns every write to
        // emailChangeAnchorNotified/emailChangeAnchorPending.
        $connection->expects(self::never())->method('executeStatement');

        $sent = [];
        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::once())->method('send')->willReturnCallback(
            static function (int $memberId, string $to, string $token) use (&$sent): bool {
                $sent = [$memberId, $to, $token];

                return true;
            },
        );

        (new ResendEmailChangeAnchorNoticeCron($connection, $anchorNotice))();

        self::assertSame([7, 'old@example.com', $pending], $sent, 'the SAME stashed plaintext must be re-sent, never a freshly generated one');
    }

    public function testDoesNothingWhenNoAnchorIsPending(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->expects(self::never())->method('executeStatement');

        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::never())->method('send');

        (new ResendEmailChangeAnchorNoticeCron($connection, $anchorNotice))();
    }
}
