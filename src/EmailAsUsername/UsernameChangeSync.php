<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\MemberModel;

/**
 * Re-applies the A2/A3 username sync after a PROGRAMMATIC email write that
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
     * True when the opt-in is on AND $newEmail cannot become the username. Callers
     * that may still refuse their own write (the confirm controller) use this to stop
     * BEFORE writing an address the username can no longer follow - "username IS the
     * email" only holds if the write is refused when the rule cannot be met.
     */
    public function rejects(string $newEmail, ?int $excludeMemberId): bool
    {
        return $this->policy->isEnabled()
            && EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($newEmail, $excludeMemberId);
    }

    /**
     * Pure decision: what tl_member.username SHOULD become once $currentEmail is
     * replaced by $newEmail, or null if it must stay untouched. Null covers two
     * cases: the opt-in (A1) is off and no email-as-username extension is active, or
     * $newEmail is not eligible as a username (A2, e.g. collision) - the username
     * always follows the email, there is no "fantasy" username to protect anymore.
     *
     * $currentUsername/$currentEmail are unused now that the follow-rule guard is
     * gone; kept for a stable signature (both call sites already have them at hand).
     */
    public function resolve(?string $currentUsername, string $currentEmail, string $newEmail, int $excludeMemberId): ?string
    {
        if ($this->policy->isEnabled()) {
            if ($this->rejects($newEmail, $excludeMemberId)) {
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
