<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\Idna;

/**
 * The canonical login-name rule (A2): login name = lowercased, trimmed email.
 * Pure (only Contao's static Idna helper) so it is the single point of truth everywhere the
 * "email as username" opt-in normalizes an address.
 */
final class CanonicalUsername
{
    public static function normalize(string $email): string
    {
        $lower = mb_strtolower(trim($email));

        // Contao's own email widgets store an IDN domain as punycode (TextField/FormText call
        // Idna::encodeEmail()), so the login name has to use the same form - a member typing the
        // Unicode address at login would otherwise never match. Idempotent for ASCII and for an
        // already encoded address. encodeEmail() answers '' for a host it cannot encode; that
        // must never become the username, so the lowercased input stays.
        $encoded = Idna::encodeEmail($lower);

        return '' !== $encoded ? $encoded : $lower;
    }
}
