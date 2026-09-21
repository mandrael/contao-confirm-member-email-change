<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailChangeAnchor;

use Contao\CoreBundle\Framework\Adapter;
use Contao\Email;
use Contao\StringUtil;
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
        self::assertStringContainsString("emailChangeAnchorPending = ''", $statements[0][0], 'the stashed plaintext must be cleared once the mail is out');
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

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_ADMIN_EMAIL'], $GLOBALS['TL_ADMIN_NAME']);

        parent::tearDown();
    }

    /**
     * Branch 1: a page context already filled $GLOBALS['TL_ADMIN_EMAIL'] - used as-is,
     * the framework/connection are never even touched.
     */
    public function testApplySenderUsesThePageContextGlobalWhenSet(): void
    {
        $GLOBALS['TL_ADMIN_EMAIL'] = 'page@example.com';
        $GLOBALS['TL_ADMIN_NAME'] = 'Page Admin';

        $email = $this->createPartialMock(Email::class, []);
        $framework = $this->createMock(\Contao\CoreBundle\Framework\ContaoFramework::class);
        $framework->expects(self::never())->method('initialize');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        (new AnchorNotice($framework, $connection, $this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)))
            ->applySender($email)
        ;

        self::assertSame('page@example.com', $email->from);
        self::assertSame('Page Admin', $email->fromName);
    }

    /**
     * Branch 2: no page context, but the global "adminEmail" setting is configured -
     * applySender() leaves the sender untouched and lets Contao\Email fall back to
     * that same global setting itself (core Email::send()).
     */
    public function testApplySenderLeavesTheSenderUntouchedWhenTheGlobalConfigHasOne(): void
    {
        $email = $this->createPartialMock(Email::class, []);
        $configAdapter = $this->createConfiguredAdapterMock(['get' => 'config@example.com']);
        $configAdapter->expects(self::once())->method('get')->with('adminEmail');
        $framework = $this->createMock(\Contao\CoreBundle\Framework\ContaoFramework::class);
        $framework->expects(self::once())->method('initialize');
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $key): ?Adapter => \Contao\Config::class === $key ? $configAdapter : null,
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        (new AnchorNotice($framework, $connection, $this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)))
            ->applySender($email)
        ;

        self::assertNull($email->from);
        self::assertNull($email->fromName);
    }

    /**
     * Branch 3: neither the page context nor the global setting has an address - falls
     * back to the first root page's adminEmail, split into name/address the same way
     * Contao's own "Name [address]" friendly format works.
     */
    public function testApplySenderFallsBackToTheFirstRootPageWithAnAdminEmail(): void
    {
        $email = $this->createPartialMock(Email::class, []);
        $configAdapter = $this->createConfiguredAdapterMock(['get' => '']);
        $framework = $this->createContaoFrameworkMock([
            \Contao\Config::class => $configAdapter,
            StringUtil::class => new Adapter(StringUtil::class),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')
            ->with(self::stringContains('tl_page'))
            ->willReturn(['Root Admin [root@example.com]'])
        ;

        (new AnchorNotice($framework, $connection, $this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)))
            ->applySender($email)
        ;

        self::assertSame('root@example.com', $email->from);
        self::assertSame('Root Admin', $email->fromName);
    }

    public function testApplySenderDoesNotGuessBetweenSeveralRootSenders(): void
    {
        $email = $this->createPartialMock(Email::class, []);
        $framework = $this->createContaoFrameworkMock([
            \Contao\Config::class => $this->createConfiguredAdapterMock(['get' => '']),
            StringUtil::class => new Adapter(StringUtil::class),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['one@example.com', 'two@example.com']);

        (new AnchorNotice($framework, $connection, $this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)))
            ->applySender($email)
        ;

        self::assertNull($email->from);
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
