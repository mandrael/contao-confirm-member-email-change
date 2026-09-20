<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor;

/**
 * A8: pure rules for the "revoke via the old address" security anchor. Used by
 * ConfirmEmailChangeController (creates/keeps the anchor on a confirmed change) and
 * RevokeEmailChangeController (consumes it) alike, with no Contao dependency, so both
 * the chain rule and the token hashing can be reasoned about without a framework
 * bootstrap.
 */
final class EmailChangeAnchorPolicy
{
    /**
     * Anchor validity window: 14 days (raised from the plan's original 7 days per
     * Michael's amendment on 2026-09-19). Every "valid for N days" mail text must
     * derive N from this constant via ttlDays() rather than hard-coding it a second
     * time.
     */
    public const TTL_SECONDS = 14 * 24 * 60 * 60;

    public static function ttlDays(): int
    {
        return (int) (self::TTL_SECONDS / 86400);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * An anchor is valid as long as a hash is stored and it has not expired yet. A
     * used or never-created anchor has an empty hash (see the DCA default and the
     * revoke controller, which clears it on success).
     */
    public static function hasValidAnchor(string $storedHash, int $expiresAt, int $now): bool
    {
        return '' !== $storedHash && $expiresAt > $now;
    }

    /**
     * Constant-time comparison of the submitted token against the stored hash - the
     * SQL lookup already matches on the hash, this is a defense-in-depth re-check
     * that never trusts the database row alone.
     */
    public static function matchesToken(string $storedHash, string $providedToken): bool
    {
        return '' !== $storedHash && hash_equals($storedHash, self::hashToken($providedToken));
    }
}
