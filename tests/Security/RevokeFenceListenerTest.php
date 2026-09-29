<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Security;

use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Security\AccountCredentialReset;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Security\RevokeFenceListener;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\ConnectionMockTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A request of the previous holder that was already running when the revoke committed
 * must not keep what it writes afterwards - including Contao's User::save(), which puts
 * back the whole member row it loaded before the revoke.
 */
class RevokeFenceListenerTest extends ContaoTestCase
{
    use ConnectionMockTrait;

    private const MARKER_SQL = 'FROM tl_member_email_revoke';

    public function testAppliesTheRevokeAgainWhenItCommittedDuringTheRequest(): void
    {
        $now = time();
        $connection = $this->createConnectionMock([
            self::MARKER_SQL => ['tstamp' => $now, 'email' => 'owner@example.com', 'username' => 'owner@example.com', 'passwordDigest' => ''],
            'FOR UPDATE' => ['username' => 'attacker@example.com', 'password' => 'new-hash'],
        ]);

        $purger = $this->createMock(UnconfirmedTokenPurger::class);
        $purger->expects(self::once())->method('purgeAll')->with(7);

        $this->listener($connection, purger: $purger)($this->event($now - 1));

        self::assertSame('begin', $this->log[1]);
        self::assertStringContainsString('FOR UPDATE', $this->log[2]);
        self::assertContains('commit', $this->log);

        $restore = $this->statementsContaining('UPDATE tl_member SET email')[0] ?? null;
        self::assertNotNull($restore);
        self::assertSame('owner@example.com', $restore['params'][0]);
        self::assertStringContainsString("emailChangeAnchorHash = ''", $restore['sql']);
        self::assertSame('owner@example.com', $this->statementsContaining('SET username')[0]['params'][0] ?? null);

        $reset = $this->statementsContaining('UPDATE tl_member SET password')[0] ?? null;
        self::assertNotNull($reset);
        self::assertStringStartsWith('invalidated$', $reset['params'][0]);
        self::assertSame(['attacker@example.com', 'owner@example.com', 'session@example.com'], $this->statementsContaining('DELETE FROM rememberme_token')[0]['params'][0] ?? null);
    }

    /**
     * The revoke reads its clock before the commit and may wait on locks in between: a
     * request that started a few seconds after the marker's time still overlaps.
     */
    public function testARequestStartedShortlyAfterTheMarkerTimeIsStillFenced(): void
    {
        self::assertTrue(RevokeFenceListener::overlaps(1000, 1000 + RevokeFenceListener::MARGIN, 1005));
        self::assertFalse(RevokeFenceListener::overlaps(1000, 1001 + RevokeFenceListener::MARGIN, 1011));
    }

    /**
     * A stale REQUEST_TIME (worker runtime) must not fence the rightful owner forever.
     */
    public function testAnOldMarkerNoLongerCounts(): void
    {
        self::assertFalse(RevokeFenceListener::overlaps(1000, 0, 1001 + RevokeFenceListener::WINDOW));
        self::assertFalse(RevokeFenceListener::overlaps(0, 0, 10));
    }

    /**
     * A write-back that lands after the time window - a slow request, or one that loaded
     * the row from a first write-back - brings the replaced password hash back. That alone
     * applies the revoke again, whenever and in whichever request it shows up.
     */
    public function testALateWriteBackOfTheReplacedPasswordIsCaughtByItsHash(): void
    {
        $old = time() - 3600;
        $connection = $this->createConnectionMock(
            [
                self::MARKER_SQL => ['tstamp' => $old, 'email' => 'owner@example.com', 'username' => 'owner@example.com', 'passwordDigest' => RevokeFenceListener::passwordDigest('replaced-hash')],
                'FOR UPDATE' => ['username' => 'attacker@example.com', 'password' => 'replaced-hash'],
            ],
            ['SELECT password FROM tl_member' => 'replaced-hash'],
        );

        $this->listener($connection)($this->event(time()));

        self::assertSame('owner@example.com', $this->statementsContaining('UPDATE tl_member SET email')[0]['params'][0] ?? null);
        self::assertContains('commit', $this->log);
    }

    /**
     * The rightful owner's new password has a new hash: outside the time window nothing
     * happens to her.
     */
    public function testTheOwnersNewPasswordIsLeftAlone(): void
    {
        $connection = $this->createConnectionMock(
            [self::MARKER_SQL => ['tstamp' => time() - 3600, 'email' => 'owner@example.com', 'username' => 'owner@example.com', 'passwordDigest' => RevokeFenceListener::passwordDigest('replaced-hash')]],
            ['SELECT password FROM tl_member' => 'owners-new-hash'],
        );

        $this->listener($connection)($this->event(time()));

        self::assertNotContains('begin', $this->log);
        self::assertSame([], $this->statements);
    }

    public function testLeavesTheRequestAloneWithoutARecentMarker(): void
    {
        $connection = $this->createConnectionMock([self::MARKER_SQL => false]);

        $this->listener($connection)($this->event(time()));

        self::assertNotContains('begin', $this->log);
        self::assertSame([], $this->statements);
    }

    /**
     * Updated but not migrated yet: the missing table must not break every request, and
     * a transaction somebody else opened is none of this listener's business.
     */
    public function testAFailingLookupIsLoggedNotThrown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('Unknown table'));
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('rollBack');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('member ID 7'));

        $this->listener($connection, $logger)($this->event(time()));
    }

    public function testIgnoresRequestsWithoutAFrontendUser(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        (new RevokeFenceListener($security, $connection, $this->createStub(UnconfirmedTokenPurger::class), new AccountCredentialReset($connection)))($this->event(time()));
    }

    private function listener(Connection $connection, LoggerInterface|null $logger = null, UnconfirmedTokenPurger|null $purger = null): RevokeFenceListener
    {
        $user = $this->createStub(FrontendUser::class);
        $user->method('__get')->willReturnMap([['id', 7]]);
        $user->method('getUserIdentifier')->willReturn('session@example.com');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new RevokeFenceListener($security, $connection, $purger ?? $this->createStub(UnconfirmedTokenPurger::class), new AccountCredentialReset($connection), $logger);
    }

    private function event(int $started): ResponseEvent
    {
        $request = Request::create('https://example.com/login.html', 'POST');
        $request->server->set('REQUEST_TIME', $started);

        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response());
    }
}
