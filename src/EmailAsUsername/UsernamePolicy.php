<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Contao\Validator;

/**
 * A2: the canonical eligibility check for "may this email become a
 * tl_member.username?" – length, character set (Contao's own "extnd" rule,
 * the same one the username field already validates against) and uniqueness
 * against every OTHER member. Used by every sync point (A3) and by the
 * member-email:sync-usernames command (A5).
 */
class UsernamePolicy
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function evaluate(string $email, ?int $excludeMemberId = null): EligibilityReason
    {
        $email = trim($email);

        if ('' === $email) {
            return EligibilityReason::EmailEmpty;
        }

        $username = CanonicalUsername::normalize($email);

        if (mb_strlen($username) > 64) {
            return EligibilityReason::TooLong;
        }

        $validatorAdapter = $this->framework->getAdapter(Validator::class);

        if (!$validatorAdapter->isExtendedAlphanumeric($username)) {
            return EligibilityReason::Invalid;
        }

        $memberAdapter = $this->framework->getAdapter(MemberModel::class);

        $conflict = null !== $excludeMemberId
            ? $memberAdapter->findOneBy(['username=?', 'id!=?'], [$username, $excludeMemberId])
            : $memberAdapter->findOneBy(['username=?'], [$username]);

        if (null !== $conflict) {
            return EligibilityReason::Collision;
        }

        return EligibilityReason::Eligible;
    }
}
