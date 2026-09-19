<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

/**
 * The canonical login-name rule (A2): login name = lowercased, trimmed email.
 * Pure and dependency-free so it is the single point of truth everywhere the
 * "email as username" opt-in normalizes an address.
 */
final class CanonicalUsername
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
