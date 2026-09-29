<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Security;

use Contao\CoreBundle\Entity\WebauthnCredential;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Takes away every way back into an account that does not go through the restored
 * address: password, remember-me logins, two-factor setup, trusted devices and
 * passkeys. Whoever held the account could have set up any of them, so after a
 * revoke none of them can be trusted; the owner signs in again via "lost password"
 * and sets them up anew.
 *
 * Runs inside the caller's transaction and has no error boundary of its own: a revoke
 * that leaves one of these alive is no revoke.
 */
final class AccountCredentialReset
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string|null> $usernames every login name the remember-me series may be stored under
     */
    public function reset(int $memberId, array $usernames): void
    {
        // Contao User::isEqualTo() compares the password, so the new hash also ends
        // every session. trustedTokenVersion + 1 voids the "trusted device" cookies.
        $this->connection->executeStatement(
            'UPDATE tl_member SET password = ?, useTwoFactor = 0, secret = NULL, backupCodes = NULL, trustedTokenVersion = trustedTokenVersion + 1 WHERE id = ?',
            [self::invalidatedPasswordHash(), $memberId],
        );

        // Symfony 6.4's PersistentRememberMeHandler reloads the user by identifier
        // without looking at the password, so a stored series would sign straight back in.
        $usernames = array_values(array_unique(array_filter($usernames, static fn (?string $u): bool => null !== $u && '' !== $u)));

        if ([] !== $usernames) {
            $this->connection->executeStatement(
                'DELETE FROM rememberme_token WHERE username IN (?)',
                [$usernames],
                [ArrayParameterType::STRING],
            );
        }

        // Passkeys (where the Contao version has them) sign in by user handle, independent of password and username.
        if (class_exists(WebauthnCredential::class)) {
            $this->connection->executeStatement(
                'DELETE FROM webauthn_credentials WHERE userHandle = ?',
                ['frontend.'.$memberId],
            );
        }
    }

    /**
     * Not a hash format any of Contao's chained password hashers (native → sodium →
     * pbkdf2/message-digest behind the "auto" config) recognizes, so every verify()
     * returns false instead of throwing. Login by password becomes impossible without
     * touching tl_member.login, which stays operator-owned.
     */
    public static function invalidatedPasswordHash(): string
    {
        return 'invalidated$'.bin2hex(random_bytes(32));
    }
}
