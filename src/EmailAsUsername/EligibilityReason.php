<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

/**
 * Outcome of UsernamePolicy::evaluate() – also the case classes reported by
 * the member-email:sync-usernames console command (A5), so keep the names
 * stable, they are user-facing there.
 */
enum EligibilityReason
{
    case Eligible;
    case EmailEmpty;
    case TooLong;
    case Invalid;
    case Collision;
}
