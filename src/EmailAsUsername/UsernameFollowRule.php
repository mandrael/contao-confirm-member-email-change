<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

/**
 * A3: decides whether the automatic sync is even allowed to touch a member's
 * username – never for a "fantasy" (deliberately chosen) username. Pure and
 * dependency-free.
 */
final class UsernameFollowRule
{
    /**
     * @param string|null $currentUsername the member's username BEFORE the sync
     * @param string      $previousEmail   the member's email BEFORE the change being synced
     */
    public static function shouldFollow(?string $currentUsername, string $previousEmail): bool
    {
        if (null === $currentUsername || '' === $currentUsername) {
            return true;
        }

        return 0 === strcasecmp($currentUsername, trim($previousEmail));
    }
}
