<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller\RevokeEmailChangeController;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A8: GET must stay strictly read-only (a mail scanner following the link must not
 * trigger anything), POST re-validates everything under lock. Covers the required
 * rot-belege: GET does nothing, an expired anchor is rejected, and an address
 * collision aborts without revealing the reason - all with the same generic outcome.
 */
class RevokeEmailChangeControllerTest extends ContaoTestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function testGetNeverTouchesTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('executeStatement');

        $response = $this->invoke($connection, 'GET');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('<form method="post"', $response->getContent());
    }

    public function testExpiredAnchorIsRejectedAndRollsBack(): void
    {
        $row = [
            'id' => 7,
            'email' => 'compromised@example.com',
            'username' => 'johndoe',
            'emailChangeAnchorHash' => hash('sha256', self::TOKEN),
            'emailChangeAnchorEmail' => 'victim@example.com',
            'emailChangeAnchorExpires' => time() - 1, // expired
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('fetchAssociative')->willReturn($row);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('commit');

        $response = $this->invoke($connection, 'POST');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.revokeInvalid', $response->getContent());
    }

    public function testUnknownTokenIsRejectedAndRollsBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('executeStatement');

        $response = $this->invoke($connection, 'POST');

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * The restored address meanwhile belongs to another member -> abort with the
     * SAME generic message (never confirm/deny which case applies) and log only the
     * member ID, never an email address.
     */
    public function testAddressCollisionAbortsWithoutRevealingTheReason(): void
    {
        $row = [
            'id' => 7,
            'email' => 'compromised@example.com',
            'username' => 'johndoe',
            'emailChangeAnchorHash' => hash('sha256', self::TOKEN),
            'emailChangeAnchorEmail' => 'victim@example.com',
            'emailChangeAnchorExpires' => time() + 3600,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('fetchAssociative')->willReturn($row);
        $connection->method('fetchOne')->willReturn(1); // someone else already has that address
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('executeStatement');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::logicalNot(self::stringContains('victim@example.com')));

        $response = $this->invoke($connection, 'POST', $logger);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testSuccessfulRevokeRestoresTheOldAddressAndInvalidatesThePassword(): void
    {
        $row = [
            'id' => 7,
            'email' => 'compromised@example.com',
            'username' => 'johndoe',
            'emailChangeAnchorHash' => hash('sha256', self::TOKEN),
            'emailChangeAnchorEmail' => 'victim@example.com',
            'emailChangeAnchorExpires' => time() + 3600,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('fetchAssociative')->willReturn($row);
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::never())->method('rollBack');

        $updateParams = null;
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$updateParams): int {
                $updateParams = $params;

                return 1;
            },
        );

        $tokenPurger = $this->createMock(UnconfirmedTokenPurger::class);
        $tokenPurger->expects(self::once())->method('purgeAll')->with(7);

        $connection->expects(self::once())->method('commit');

        $response = $this->invoke($connection, 'POST', null, $tokenPurger);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($updateParams);
        // email, username, password, hash, oldEmailField, tstamp, id
        self::assertSame('victim@example.com', $updateParams[0]);
        self::assertNotSame('', $updateParams[2], 'password must be overwritten, never left empty');
        self::assertSame('', $updateParams[3]);
        self::assertSame('', $updateParams[4]);
        self::assertSame(7, $updateParams[6]);
    }

    private function invoke(
        Connection $connection,
        string $method,
        ?LoggerInterface $logger = null,
        ?UnconfirmedTokenPurger $tokenPurger = null,
    ): \Symfony\Component\HttpFoundation\Response {
        $framework = $this->createStub(ContaoFramework::class);

        // Opt-in off, no email-as-username extension in the test environment -> the
        // real resolve() logic returns null (username stays as stored) either way.
        $emailAsUsernamePolicy = $this->createStub(EmailAsUsernamePolicy::class);
        $emailAsUsernamePolicy->method('isEnabled')->willReturn(false);
        $usernameChangeSync = new UsernameChangeSync($emailAsUsernamePolicy, $this->createStub(UsernamePolicy::class));

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $requestStack = new RequestStack();
        $request = Request::create('https://example.com/confirm-email-change/revoke/'.self::TOKEN, $method);
        $requestStack->push($request);

        $csrfTokenManager = $this->createStub(ContaoCsrfTokenManager::class);
        $csrfTokenManager->method('getDefaultTokenValue')->willReturn('csrf-value');

        $controller = new RevokeEmailChangeController(
            $framework,
            $connection,
            $usernameChangeSync,
            $tokenPurger ?? $this->createStub(UnconfirmedTokenPurger::class),
            $security,
            $translator,
            $requestStack,
            $csrfTokenManager,
            $logger,
        );

        return $controller($request, self::TOKEN);
    }
}
