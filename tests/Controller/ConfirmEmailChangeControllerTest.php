<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Controller;

use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\Email;
use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller\ConfirmEmailChangeController;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\ConnectionMockTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The username-follow decision itself (A2/A3) is covered by UsernameChangeSyncTest -
 * this class covers what is specific to the controller: the locking protocol, the A6
 * token revocation, the A8 anchor lifecycle and the error boundary around the commit.
 */
class ConfirmEmailChangeControllerTest extends ContaoTestCase
{
    use ConnectionMockTrait;

    private const MEMBER_SQL = 'FROM tl_member WHERE id = ? FOR UPDATE';

    private const TOKEN_SQL = 'FROM tl_opt_in WHERE token';

    private const RELATED_SQL = 'FROM tl_opt_in_related';

    /**
     * Codex 2: the member row lock has to come FIRST. Everything the decision rests on
     * is read after it and only with locking reads, so nothing can slip in between the
     * check and the write.
     */
    public function testLocksTheMemberRowBeforeReadingAnythingElse(): void
    {
        $connection = $this->connection();
        $this->invokeController($connection);

        self::assertSame('begin', $this->log[0]);
        self::assertStringContainsString('read:SELECT id, email', $this->log[1]);
        self::assertStringContainsString(self::MEMBER_SQL, $this->log[1]);

        foreach ($this->log as $entry) {
            // tl_opt_in_related rows are written once when the token is created and are
            // never updated - only reachable via the ALREADY-locked tl_opt_in.id, so they
            // need no lock of their own (Review Runde 3).
            if (str_starts_with($entry, 'read:SELECT') && !str_contains($entry, 'COUNT(*)') && !str_contains($entry, self::RELATED_SQL)) {
                self::assertStringContainsString('FOR UPDATE', $entry, 'every re-read after the lock must be a locking read');
            }
        }

        self::assertContains('commit', $this->log);
    }

    /**
     * Codex 2, the actual race: the token object was loaded BEFORE the lock, so its
     * "not confirmed yet" may be stale. The fresh row under the lock decides.
     */
    public function testAParallelConfirmationThatWonTheLockIsNotAppliedTwice(): void
    {
        $connection = $this->connection(tokenRow: ['confirmedOn' => time(), 'invalidatedThrough' => '', 'createdOn' => time(), 'email' => 'new@example.com']);

        $optInToken = $this->optInToken();
        $optInToken->expects(self::never())->method('confirm');

        $response = $this->invokeController($connection, optInToken: $optInToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.alreadyConfirmed', (string) $response->getContent());
        self::assertSame([], $this->statements, 'nothing may be written when the link was consumed in parallel');
        self::assertContains('rollBack', $this->log);
        self::assertNotContains('commit', $this->log);
    }

    /**
     * Runde 2, Hinweis (Codex): the member/token relation is re-verified from the freshly
     * locked tl_opt_in row, not trusted from the pre-lock read that determined which
     * member row to lock in the first place. Review Runde 3: the relation now comes from
     * tl_opt_in_related (pid/relTable/relId), not a non-existent tl_opt_in.relatedRecords
     * column - this row points at member 99 while the locked member is 7 (the default).
     */
    public function testARelatedRecordsMismatchUnderTheLockIsRejected(): void
    {
        $connection = $this->connection(
            tokenRow: [
                'id' => 1,
                'confirmedOn' => 0,
                'invalidatedThrough' => '',
                'createdOn' => time(),
                'email' => 'new@example.com',
            ],
            relatedRows: [['relTable' => 'tl_member', 'relId' => 99]],
        );

        $optInToken = $this->optInToken();
        $optInToken->expects(self::never())->method('confirm');

        $response = $this->invokeController($connection, optInToken: $optInToken);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.invalid', (string) $response->getContent());
        self::assertSame([], $this->statements);
        self::assertContains('rollBack', $this->log);
        self::assertNotContains('commit', $this->log);
    }

    /**
     * A6: a still-open core password-reset link for the OLD address must die with a
     * successful confirmation, so it cannot be used to take over the recovery channel.
     */
    public function testRevokesTheStaleCorePasswordResetTokenOnSuccess(): void
    {
        $tokenPurger = $this->createMock(UnconfirmedTokenPurger::class);
        $purged = [];
        $tokenPurger->expects(self::exactly(2))->method('purge')->willReturnCallback(
            static function (int $memberId, string $prefix) use (&$purged): void {
                self::assertSame(7, $memberId);
                $purged[] = $prefix;
            },
        );

        $response = $this->invokeController($this->connection(), tokenPurger: $tokenPurger);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pw', 'mdacc'], $purged);

        $update = $this->statementsContaining('UPDATE tl_member SET email')[0] ?? null;
        self::assertNotNull($update);
        self::assertSame('new@example.com', $update['params'][0]);
    }

    /**
     * A8: no anchor exists yet -> a fresh one is created (hash of a random token, OLD
     * address, a 14-day expiry) and the old address gets the notice WITH the link. The
     * "notified" column stays 0 until the mail really went out (Codex 6).
     */
    public function testCreatesAFreshAnchorAndSendsTheLinkedNoticeWhenNoneExists(): void
    {
        $sent = [];
        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::once())->method('send')->willReturnCallback(
            static function (int $memberId, string $to, string $plainToken) use (&$sent): bool {
                $sent = [$memberId, $to, $plainToken];

                return true;
            },
        );

        $before = time();
        $this->invokeController($this->connection(), anchorNotice: $anchorNotice);
        $after = time();

        $anchor = $this->statementsContaining('emailChangeAnchorHash = ?')[0] ?? null;
        self::assertNotNull($anchor);
        self::assertStringContainsString('emailChangeAnchorNotified = 0', $anchor['sql']);
        self::assertSame(hash('sha256', $sent[2]), $anchor['params'][0], 'the hash is stored for lookup');
        self::assertSame('old@example.com', $anchor['params'][1]);
        self::assertGreaterThanOrEqual($before + EmailChangeAnchorPolicy::TTL_SECONDS, (int) $anchor['params'][2]);
        self::assertLessThanOrEqual($after + EmailChangeAnchorPolicy::TTL_SECONDS, (int) $anchor['params'][2]);
        self::assertStringContainsString('emailChangeAnchorPending = ?', $anchor['sql']);
        self::assertSame($sent[2], $anchor['params'][3], 'the plaintext is stashed for the pending-send window (Runde 2, Befund 2)');
        self::assertSame([7, 'old@example.com'], [$sent[0], $sent[1]]);
    }

    /**
     * A8 chain rule: a still-valid anchor from an earlier change must NOT be overwritten
     * by a second confirmed change - otherwise an attacker who just took over the account
     * could erase the real owner's own way back in. The old address of THIS change gets
     * the notice WITHOUT a link instead.
     */
    public function testKeepsAnExistingValidAnchorUnchanged(): void
    {
        $connection = $this->connection(memberRow: [
            'id' => 7,
            'email' => 'compromised@example.com',
            'username' => 'johndoe',
            'emailChangeAnchorHash' => str_repeat('a', 64),
            'emailChangeAnchorExpires' => time() + 3600,
        ]);

        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->expects(self::once())->method('sendTo')->with('compromised@example.com');

        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::never())->method('send');

        $this->invokeController($connection, email: $email, anchorNotice: $anchorNotice);

        self::assertSame([], $this->statementsContaining('emailChangeAnchorHash = ?'));
        self::assertSame('MSC.confirmEmailChange.revokeNoticeChainedText', $email->text);
    }

    /**
     * A8: an EXPIRED anchor is not "still valid" - the chain rule does not apply and a
     * fresh anchor replaces it.
     */
    public function testReplacesAnExpiredAnchor(): void
    {
        $connection = $this->connection(memberRow: [
            'id' => 7,
            'email' => 'old@example.com',
            'username' => 'johndoe',
            'emailChangeAnchorHash' => str_repeat('a', 64),
            'emailChangeAnchorExpires' => time() - 1,
        ]);

        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->expects(self::once())->method('send')->willReturn(true);

        $this->invokeController($connection, anchorNotice: $anchorNotice);

        $anchor = $this->statementsContaining('emailChangeAnchorHash = ?')[0] ?? null;
        self::assertNotNull($anchor);
        self::assertNotSame(str_repeat('a', 64), $anchor['params'][0]);
    }

    /**
     * Codex 4 / "zwingend": the address is not written when it can no longer become the
     * login name. The link stays unconsumed so a resolvable collision can still be fixed.
     */
    public function testDoesNotConfirmWhenTheAddressCannotBecomeTheLoginName(): void
    {
        $optInToken = $this->optInToken();
        $optInToken->expects(self::never())->method('confirm');

        $response = $this->invokeController(
            $this->connection(),
            optInToken: $optInToken,
            usernameChangeSync: $this->usernameChangeSync(true, EligibilityReason::Collision),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.usernameRejected', (string) $response->getContent());
        self::assertSame([], $this->statements);
        self::assertContains('rollBack', $this->log);
    }

    /**
     * Codex 7: a technical failure rolls back and shows a page, it never escapes as an
     * exception - and it never leaves the transaction open.
     */
    public function testATechnicalFailureRollsBackAndShowsAPage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(function (): void { $this->log[] = 'begin'; });
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('database gone'));
        $connection->expects(self::once())->method('rollBack');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = $this->invokeController($connection, logger: $logger);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * Codex 6/7: the change is committed before the notice is sent, so a failing mail
     * must never present the applied change as a failure.
     */
    public function testAFailingNoticeDoesNotTurnTheCommittedChangeIntoAFailure(): void
    {
        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = $this->invokeController($this->connection(), anchorNotice: $anchorNotice, logger: $logger);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.success', (string) $response->getContent());
        self::assertContains('commit', $this->log);
    }

    /**
     * The mailed revoke link may be opened in a browser where somebody ELSE is signed
     * in - that session is none of this request's business, so it must not be logged
     * out just because SOME member's username changed.
     */
    public function testDoesNotLogOutAFrontendUserThatIsNotTheConfirmedMember(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 99]));
        $security->expects(self::never())->method('logout');

        $response = $this->invokeController(
            $this->connection(),
            usernameChangeSync: $this->usernameChangeSync(true, EligibilityReason::Eligible),
            security: $security,
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The confirmed member (ID 7, the default fixture) IS signed in -> logged out
     * exactly once, without CSRF validation (the request carries an opt-in token, not
     * a CSRF-protected logout form).
     */
    public function testLogsOutTheConfirmedFrontendUser(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7]));
        $security->expects(self::once())->method('logout')->with(false)->willReturn(new Response());

        $response = $this->invokeController(
            $this->connection(),
            usernameChangeSync: $this->usernameChangeSync(true, EligibilityReason::Eligible),
            security: $security,
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Own try block in afterCommit(): a failed anchor notice must never skip the
     * logout that a changed username requires.
     */
    public function testTheLogoutStillHappensWhenTheAnchorNoticeFails(): void
    {
        $anchorNotice = $this->createMock(AnchorNotice::class);
        $anchorNotice->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7]));
        $security->expects(self::once())->method('logout')->with(false)->willReturn(new Response());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $response = $this->invokeController(
            $this->connection(),
            anchorNotice: $anchorNotice,
            usernameChangeSync: $this->usernameChangeSync(true, EligibilityReason::Eligible),
            security: $security,
            logger: $logger,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('MSC.confirmEmailChange.success', (string) $response->getContent());
    }

    /**
     * DeepSeek H2: the URL carries the token, so the page must not be cached or indexed.
     */
    public function testTheConfirmationPageIsNeitherCachedNorIndexed(): void
    {
        $response = $this->invokeController($this->connection());

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    /**
     * @param array<string, mixed>|null              $memberRow
     * @param array<string, mixed>|null              $tokenRow
     * @param list<array<string, mixed>>|null         $relatedRows rows of tl_opt_in_related (relTable/relId)
     */
    private function connection(array|null $memberRow = null, array|null $tokenRow = null, array|null $relatedRows = null): Connection
    {
        return $this->createConnectionMock(
            [
                self::MEMBER_SQL => $memberRow ?? [
                    'id' => 7,
                    'email' => 'old@example.com',
                    'username' => 'johndoe',
                    'emailChangeAnchorHash' => '',
                    'emailChangeAnchorExpires' => 0,
                ],
                self::TOKEN_SQL => $tokenRow ?? [
                    'id' => 1,
                    'confirmedOn' => 0,
                    'invalidatedThrough' => '',
                    'createdOn' => time(),
                    'email' => 'new@example.com',
                ],
            ],
            ['COUNT(*)' => 0],
            [self::RELATED_SQL => $relatedRows ?? [['relTable' => 'tl_member', 'relId' => 7]]],
        );
    }

    private function optInToken(): OptInTokenInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $optInToken = $this->createMock(OptInTokenInterface::class);
        $optInToken->method('getIdentifier')->willReturn('email-abc123');
        $optInToken->method('isConfirmed')->willReturn(false);
        $optInToken->method('isValid')->willReturn(true);
        $optInToken->method('getRelatedRecords')->willReturn(['tl_member' => [7]]);
        $optInToken->method('getEmail')->willReturn('new@example.com');

        return $optInToken;
    }

    private function usernameChangeSync(bool $enabled = false, EligibilityReason $reason = EligibilityReason::Eligible): UsernameChangeSync
    {
        $policy = $this->createStub(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        $usernamePolicy = $this->createStub(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn($reason);

        return new UsernameChangeSync($policy, $usernamePolicy);
    }

    private function invokeController(
        Connection $connection,
        UnconfirmedTokenPurger|null $tokenPurger = null,
        Email|null $email = null,
        AnchorNotice|null $anchorNotice = null,
        OptInTokenInterface|null $optInToken = null,
        UsernameChangeSync|null $usernameChangeSync = null,
        LoggerInterface|null $logger = null,
        Security|null $security = null,
    ): Response {
        $email ??= $this->createPartialMock(Email::class, ['sendTo']);

        $framework = $this->createContaoFrameworkMock([], [Email::class => $email]);

        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::once())->method('find')->with('email-abc123')->willReturn($optInToken ?? $this->optInToken());

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new ConfirmEmailChangeController(
            $framework,
            $optIn,
            $translator,
            $requestStack,
            $security ?? $this->createStub(Security::class),
            $usernameChangeSync ?? $this->usernameChangeSync(),
            $tokenPurger ?? $this->createStub(UnconfirmedTokenPurger::class),
            $connection,
            $anchorNotice ?? $this->createStub(AnchorNotice::class),
            $logger,
        );

        return $controller('email-abc123');
    }
}
