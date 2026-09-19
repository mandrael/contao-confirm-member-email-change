<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailChangeAnchor;

use Contao\Email;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Codex 6: only the hash of the revoke token is stored, so the plaintext link exists
 * nowhere but in this mail. "Notified" may therefore only be set once the mail is out.
 */
class AnchorNoticeTest extends ContaoTestCase
{
    private const TOKEN = 'a1b2c3d4';

    public function testMarksTheAnchorAsNotifiedOnlyAfterTheMailWentOut(): void
    {
        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->expects(self::once())->method('sendTo')->with('old@example.com')->willReturn(true);

        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        self::assertTrue($this->notice($email, $connection)->send(7, 'old@example.com', self::TOKEN));
        self::assertCount(1, $statements);
        self::assertStringContainsString('emailChangeAnchorNotified = 1', $statements[0][0]);
        self::assertSame([7, hash('sha256', self::TOKEN)], $statements[0][1]);
    }

    public function testAFailedSendLeavesTheAnchorUnnotified(): void
    {
        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->method('sendTo')->willThrowException(new \RuntimeException('smtp down'));

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::logicalAnd(
            self::stringContains('member ID 7'),
            self::logicalNot(self::stringContains('old@example.com')),
        ));

        self::assertFalse($this->notice($email, $connection, $logger)->send(7, 'old@example.com', self::TOKEN));
    }

    /**
     * sendTo() returns false instead of throwing when there is no recipient - that is
     * not a successful send either.
     */
    public function testAnUnsentMailIsNotTreatedAsSent(): void
    {
        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->method('sendTo')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        self::assertFalse($this->notice($email, $connection)->send(7, 'old@example.com', self::TOKEN));
    }

    private function notice(Email $email, Connection $connection, LoggerInterface|null $logger = null): AnchorNotice
    {
        $framework = $this->createContaoFrameworkMock([], [Email::class => $email]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/confirm-email-change/revoke/'.self::TOKEN);

        return new AnchorNotice($framework, $connection, $translator, $urlGenerator, $logger);
    }
}
