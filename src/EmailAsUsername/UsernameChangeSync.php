<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\MemberModel;

/**
 * Re-applies the A2/A3 username-follow rule after a PROGRAMMATIC email write that
 * bypasses DCA save_callbacks. Used by ConfirmEmailChangeController (forward: old
 * email -> new email) and, per A8, by RevokeEmailChangeController (backward: the
 * compromised email -> the restored old address) - the rule is identical either way,
 * only the direction of "current" vs "new" email differs.
 */
final class UsernameChangeSync
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
    ) {
    }

    /**
     * Convenience wrapper for callers that already hold a MemberModel: reads its
     * current username/email, applies resolve() and – if it decides on a change –
     * writes the property (the caller still has to ->save() the model).
     *
     * @return bool whether the username was changed
     */
    public function apply(MemberModel $member, string $newEmail): bool
    {
        $resolved = $this->resolve((string) $member->username, (string) $member->email, $newEmail, (int) $member->id);

        if (null === $resolved) {
            return false;
        }

        $member->username = $resolved;

        return true;
    }

    /**
     * Pure decision: what tl_member.username SHOULD become once $currentEmail is
     * replaced by $newEmail, or null if it must stay untouched. Null covers three
     * cases: the opt-in (A1) is off and no email-as-username extension is active, a
     * "fantasy" username that never followed the email to begin with (A3), or
     * $newEmail is not eligible as a username (A2, e.g. collision).
     *
     * Note the terminal42/contao-mailusername fallback deliberately skips the
     * follow-rule check: that extension is a pure verbatim sync with no login
     * decorator, so the username MUST follow or login with the new address breaks.
     */
    public function resolve(?string $currentUsername, string $currentEmail, string $newEmail, int $excludeMemberId): ?string
    {
        if ($this->policy->isEnabled()) {
            if (!UsernameFollowRule::shouldFollow($currentUsername, $currentEmail)) {
                return null; // A "fantasy" username stays untouched.
            }

            if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($newEmail, $excludeMemberId)) {
                return null; // Not eligible as a username - do not fail the whole caller over it.
            }

            return CanonicalUsername::normalize($newEmail);
        }

        if (class_exists(\Terminal42\MailusernameBundle\Terminal42MailusernameBundle::class)) {
            return $newEmail;
        }

        return null;
    }
}
