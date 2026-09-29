<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Security;

use Contao\FrontendUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Closes the window a revoke leaves open. A request of the previous holder that passed
 * its session check before the revoke committed still writes afterwards: a password from
 * the profile or "change password" form, a remember-me login - and Contao's
 * User::save() (login, password upgrade, two-factor) writes back the WHOLE member row it
 * loaded before the revoke, address, login name, password and anchor included. Scripted
 * back to back, such a request is nearly always in flight.
 *
 * All of these writes happen in a request of the authenticated member, so at the end of
 * each one, with the marker in tl_member_email_revoke (which no member row write can
 * touch): apply the revoke's outcome again under the row lock if the revoke committed
 * while the request may have been running, or if the member row carries the replaced
 * password hash again - a write-back, however late, and whichever request did it. It is
 * idempotent and never hits the rightful owner, whose new password has a new hash.
 */
final class RevokeFenceListener
{
    /**
     * Seconds a request may have started after the marker's time and still overlap the
     * revoke: covers a commit that waited on locks after its clock read, and clock skew
     * between web nodes.
     */
    public const MARGIN = 10;

    /**
     * Seconds a marker counts at all. Also bounds a stale REQUEST_TIME (worker runtimes):
     * nobody signs in again with a fresh password this soon after a revoke.
     */
    public const WINDOW = 120;

    /**
     * Seconds a marker is kept for the password-hash check (and then purged by the
     * daily cron, it carries an address).
     */
    public const RETENTION = 86400;

    public function __construct(
        private readonly Security $security,
        private readonly Connection $connection,
        private readonly UnconfirmedTokenPurger $tokenPurger,
        private readonly AccountCredentialReset $credentialReset,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser) {
            return;
        }

        $memberId = (int) $user->id;
        $now = time();
        $started = $event->getRequest()->server->getInt('REQUEST_TIME') ?: $now;
        $began = false;

        // Inside the error boundary too: on a site updated without contao:migrate yet the
        // table is missing, and that must not turn every member's request into a 500.
        try {
            // Unlocked pre-check: the common case (no recent revoke) is one indexed lookup.
            $marker = $this->connection->fetchAssociative(
                'SELECT tstamp, email, username, passwordDigest FROM tl_member_email_revoke WHERE pid = ?',
                [$memberId],
            );

            if (false === $marker || (int) $marker['tstamp'] < $now - self::RETENTION) {
                return;
            }

            $overlaps = self::overlaps((int) $marker['tstamp'], $started, $now);

            if (!$overlaps && !$this->carriesReplacedPassword($marker, $this->connection->fetchOne('SELECT password FROM tl_member WHERE id = ?', [$memberId]))) {
                return;
            }

            $this->connection->beginTransaction();
            $began = true;

            $row = $this->connection->fetchAssociative('SELECT username, password FROM tl_member WHERE id = ? FOR UPDATE', [$memberId]);

            if (false !== $row && ($overlaps || $this->carriesReplacedPassword($marker, $row['password']))) {
                $this->reapply($memberId, $marker, (string) $row['username'], $user->getUserIdentifier());
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            // Only a transaction this listener opened; never somebody else's.
            if ($began && $this->connection->isTransactionActive()) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                }
            }

            $this->logger?->error(\sprintf('Fencing a request of member ID %d against an email-change revoke failed with %s.', $memberId, $e::class));
        }
    }

    public static function passwordDigest(mixed $passwordHash): string
    {
        return hash('sha256', (string) $passwordHash);
    }

    public static function overlaps(int $revokedAt, int $requestStarted, int $now): bool
    {
        return $revokedAt > 0 && $revokedAt >= $requestStarted - self::MARGIN && $revokedAt >= $now - self::WINDOW;
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function carriesReplacedPassword(array $marker, mixed $passwordHash): bool
    {
        $digest = (string) $marker['passwordDigest'];

        return '' !== $digest && false !== $passwordHash && hash_equals($digest, self::passwordDigest($passwordHash));
    }

    /**
     * @param array<string, mixed> $marker
     */
    private function reapply(int $memberId, array $marker, string $currentUsername, string $sessionIdentifier): void
    {
        $this->connection->executeStatement(
            "UPDATE tl_member SET email = ?, emailChangeAnchorHash = '', emailChangeAnchorEmail = '', emailChangeAnchorExpires = 0, emailChangeAnchorNotified = 0, emailChangeAnchorPending = '', tstamp = ? WHERE id = ?",
            [(string) $marker['email'], time(), $memberId],
        );

        $username = null === $marker['username'] ? '' : (string) $marker['username'];

        if ('' !== $username && $username !== $currentUsername) {
            try {
                $this->connection->executeStatement('UPDATE tl_member SET username = ? WHERE id = ?', [$username, $memberId]);
            } catch (UniqueConstraintViolationException) {
                // Taken meanwhile: same rule as the revoke, the name stays and is logged below.
            }
        }

        $this->tokenPurger->purgeAll($memberId);
        $this->credentialReset->reset($memberId, [$currentUsername, $username, $sessionIdentifier]);

        $this->logger?->warning(\sprintf('A request of member ID %d that overlapped an email-change revoke was fenced off: the revoke was applied again.', $memberId));
    }
}
