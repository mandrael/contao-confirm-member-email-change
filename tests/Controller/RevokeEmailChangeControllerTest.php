<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller\RevokeEmailChangeController;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Security\AccountCredentialReset;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\ConnectionMockTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A8: GET must stay strictly read-only (a mail scanner following the link must not
 * trigger anything), POST re-validates everything under the member row lock. Every
 * failure - including a technical one - ends in the same generic answer.
 */
class RevokeEmailChangeControllerTest extends ContaoTestCase
{
    use ConnectionMockTrait;

    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private const MEMBER_SQL = 'FROM tl_member WHERE id = ? FOR UPDATE';

    public function testGetNeverTouchesTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');

        $response = $this->invoke($connection, 'GET');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('<form method="post"', (string) $response->getContent());
    }

    /**
     * Codex 2: the anchor column carries no index, so the row is looked up without a
     * lock and the LOCK is taken by id - same protocol as the confirm controller, and
     * without locking every row a scan would touch.
     */
    public function testLocksByIdAndRevalidatesTheAnchorUnderTheLock(): void
    {
        $this->invoke($this->connection(), 'POST');

        self::assertStringContainsString('read:SELECT id FROM tl_member WHERE emailChangeAnchorHash', $this->log[0]);
        self::assertSame('begin', $this->log[1]);
        self::assertStringContainsString(self::MEMBER_SQL, $this->log[2]);
        self::assertContains('commit', $this->log);
    }

    public function testExpiredAnchorIsRejectedAndRollsBack(): void
    {
        $response = $this->invoke($this->connection(['emailChangeAnchorExpires' => time() - 1]), 'POST');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.revokeInvalid', (string) $response->getContent());
        self::assertSame([], $this->statements);
        self::assertContains('rollBack', $this->log);
        self::assertNotContains('commit', $this->log);
    }

    public function testUnknownTokenIsRejectedWithoutOpeningATransaction(): void
    {
        $connection = $this->createConnectionMock([], ['SELECT id FROM tl_member' => 0]);

        $response = $this->invoke($connection, 'POST');

        self::assertSame(400, $response->getStatusCode());
        self::assertNotContains('begin', $this->log);
        self::assertSame([], $this->statements);
    }

    /**
     * Whoever moved the account away can register a throwaway member with the old
     * address right afterwards. The revoke is the owner's way back and must not be
     * blockable that way: it goes through, the duplicate is logged without an address.
     */
    public function testAddressCollisionDoesNotBlockTheRevoke(): void
    {
        $connection = $this->connection(conflicts: 1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::logicalAnd(
            self::stringContains('member ID 7'),
            self::logicalNot(self::stringContains('@example.com')),
        ));

        $response = $this->invoke($connection, 'POST', $logger);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('victim@example.com', $this->statementsContaining('UPDATE tl_member SET email')[0]['params'][0] ?? null);
        self::assertContains('commit', $this->log);
    }

    /**
     * The replaced password ends sessions, but a stored remember-me series would log the
     * previous holder straight back in (Symfony 6.4 does not bind it to the password).
     */
    public function testRevokeDropsTheRememberMeLoginsInsideTheTransaction(): void
    {
        $response = $this->invoke($this->connection(), 'POST');

        self::assertSame(200, $response->getStatusCode());

        $delete = $this->statementsContaining('DELETE FROM rememberme_token')[0] ?? null;
        self::assertNotNull($delete);
        self::assertSame(['johndoe'], $delete['params'][0]);
        self::assertLessThan(array_search('commit', $this->log, true), array_search('write:'.$delete['sql'], $this->log, true));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    /**
     * A revoke that leaves the remember-me logins alive is no revoke: if their removal
     * fails, the whole transaction is rolled back and nothing is reported as done.
     */
    public function testAFailedRememberMeRemovalRollsTheRevokeBack(): void
    {
        $connection = $this->connection();
        $this->failWriteOn = 'rememberme_token';

        try {
            $response = $this->invoke($connection, 'POST');
        } finally {
            $this->failWriteOn = null;
        }

        self::assertSame(400, $response->getStatusCode());
        self::assertNotContains('commit', $this->log);
        self::assertContains('rollBack', $this->log);
    }

    /**
     * The switch was on during the takeover and is off now: the login name still IS the
     * compromised address and has to follow the restored one.
     */
    public function testALoginNameSyncedToTheCompromisedAddressFollowsEvenWithTheSwitchOff(): void
    {
        $this->invoke($this->connection(['username' => 'compromised@example.com']), 'POST');

        $update = $this->statementsContaining('UPDATE tl_member SET username')[0] ?? null;
        self::assertNotNull($update);
        self::assertSame('victim@example.com', $update['params'][0]);
    }

    /**
     * rejects() does not see every collision: terminal42/contao-mailusername writes the
     * address verbatim with the switch off, and a parallel insert can take the name after
     * the check. The UNIQUE index then must not roll the revoke back.
     */
    public function testATakenLoginNameAtWriteTimeStillLetsTheRevokeGoThrough(): void
    {
        $connection = $this->connection(['username' => 'compromised@example.com']);
        $this->failWriteOn = 'SET username';
        $this->failWriteWith = new UniqueConstraintViolationException($this->createStub(DriverException::class), null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('member ID 7'));

        try {
            $response = $this->invoke($connection, 'POST', $logger);
        } finally {
            $this->failWriteOn = null;
            $this->failWriteWith = null;
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertContains('commit', $this->log);
        self::assertSame(['compromised@example.com'], $this->statementsContaining('DELETE FROM rememberme_token')[0]['params'][0] ?? null);
    }

    /**
     * Whoever held the account could have set up two-factor login, trusted devices or a
     * passkey; none of them may survive the revoke.
     */
    public function testRevokeResetsTwoFactorAndPasskeys(): void
    {
        $this->invoke($this->connection(), 'POST');

        $reset = $this->statementsContaining('UPDATE tl_member SET password')[0] ?? null;
        self::assertNotNull($reset);
        self::assertStringStartsWith('invalidated$', $reset['params'][0]);
        self::assertStringContainsString('useTwoFactor = 0', $reset['sql']);
        self::assertStringContainsString('secret = NULL', $reset['sql']);
        self::assertStringContainsString('backupCodes = NULL', $reset['sql']);
        self::assertStringContainsString('trustedTokenVersion = trustedTokenVersion + 1', $reset['sql']);

        if (class_exists(\Contao\CoreBundle\Entity\WebauthnCredential::class)) {
            self::assertSame(['frontend.7'], $this->statementsContaining('DELETE FROM webauthn_credentials')[0]['params'] ?? null);
        }

        self::assertLessThan(array_search('commit', $this->log, true), array_search('write:'.$reset['sql'], $this->log, true));
    }

    public function testSuccessfulRevokeRestoresTheOldAddressAndInvalidatesThePassword(): void
    {
        $tokenPurger = $this->createMock(UnconfirmedTokenPurger::class);
        $tokenPurger->expects(self::once())->method('purgeAll')->with(7);

        $response = $this->invoke($this->connection(), 'POST', null, $tokenPurger);

        self::assertSame(200, $response->getStatusCode());
        self::assertContains('commit', $this->log);

        $update = $this->statementsContaining('UPDATE tl_member SET email')[0] ?? null;
        self::assertNotNull($update);
        self::assertSame('victim@example.com', $update['params'][0]);
        self::assertStringContainsString("emailChangeAnchorExpires = 0", $update['sql']);
        self::assertStringContainsString('emailChangeAnchorPending = ?', $update['sql'], 'the spent plaintext must be cleared, not just the hash');

        // The fence marker is the last write before the commit and names the outcome.
        $marker = $this->statementsContaining('INSERT INTO tl_member_email_revoke')[0] ?? null;
        self::assertNotNull($marker);
        self::assertSame(7, $marker['params'][0]);
        self::assertSame('victim@example.com', $marker['params'][2]);
        self::assertSame('johndoe', $marker['params'][3], 'the login name the revoke left');
        self::assertSame(hash('sha256', 'attacker-hash'), $marker['params'][4], 'the replaced password hash, for a late write-back');
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $marker['sql']);
        $writes = array_values(array_filter($this->log, static fn (string $e): bool => str_starts_with($e, 'write:')));
        self::assertStringContainsString('INSERT INTO tl_member_email_revoke', end($writes));

        $password = $this->statementsContaining('UPDATE tl_member SET password')[0] ?? null;
        self::assertNotNull($password);
        self::assertStringStartsWith('invalidated$', $password['params'][0], 'the password must be overwritten, never left empty');
    }

    /**
     * Codex 4 (e): a login name that cannot follow the restored address must NOT make
     * the revoke fail - it is a security function. Address and password are put right
     * regardless, only the login name stays behind, and the operator gets a log entry.
     */
    public function testACollidingLoginNameStillLetsTheRevokeGoThrough(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('member ID 7'));

        $response = $this->invoke(
            $this->connection(),
            'POST',
            $logger,
            null,
            $this->usernameChangeSync(true, EligibilityReason::Collision),
        );

        self::assertSame(200, $response->getStatusCode());

        $update = $this->statementsContaining('UPDATE tl_member SET email')[0] ?? null;
        self::assertNotNull($update);
        self::assertSame([], $this->statementsContaining('SET username'), 'the login name stays as it is');
        self::assertSame('victim@example.com', $update['params'][0]);
    }

    /**
     * Codex 7: a technical error must end in the generic answer, not in an error page
     * that says something about this account - and it must not leave a transaction open.
     */
    public function testATechnicalFailureEndsInTheGenericAnswer(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(7);
        $connection->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('database gone'));
        $connection->expects(self::once())->method('rollBack');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = $this->invoke($connection, 'POST', $logger);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.revokeInvalid', (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function connection(array $overrides = [], int $conflicts = 0): Connection
    {
        $row = [
            'id' => 7,
            'email' => 'compromised@example.com',
            'username' => 'johndoe',
            'password' => 'attacker-hash',
            'emailChangeAnchorHash' => hash('sha256', self::TOKEN),
            'emailChangeAnchorEmail' => 'victim@example.com',
            'emailChangeAnchorExpires' => time() + 3600,
            ...$overrides,
        ];

        return $this->createConnectionMock(
            [self::MEMBER_SQL => $row],
            ['SELECT id FROM tl_member' => 7, 'COUNT(*)' => $conflicts],
        );
    }

    private function usernameChangeSync(bool $enabled = false, EligibilityReason $reason = EligibilityReason::Eligible): UsernameChangeSync
    {
        $policy = $this->createStub(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        $usernamePolicy = $this->createStub(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn($reason);

        return new UsernameChangeSync($policy, $usernamePolicy);
    }

    private function invoke(
        Connection $connection,
        string $method,
        LoggerInterface|null $logger = null,
        UnconfirmedTokenPurger|null $tokenPurger = null,
        UsernameChangeSync|null $usernameChangeSync = null,
    ): Response {
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
            $this->createStub(ContaoFramework::class),
            $connection,
            $usernameChangeSync ?? $this->usernameChangeSync(),
            $tokenPurger ?? $this->createStub(UnconfirmedTokenPurger::class),
            $security,
            $translator,
            $requestStack,
            $csrfTokenManager,
            new AccountCredentialReset($connection),
            $logger,
        );

        return $controller($request, self::TOKEN);
    }
}
