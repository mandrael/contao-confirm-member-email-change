<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

/**
 * Outcome of UsernamePolicy::evaluate() – also the case classes reported by
 * the member-email:sync-usernames console command (A5), which maps each
 * case to the label an operator sees (SyncUsernamesCommand::classify()).
 */
enum EligibilityReason
{
    case Eligible;
    case EmailEmpty;
    case TooLong;
    case Invalid;
    case Collision;
}
