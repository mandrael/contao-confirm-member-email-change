<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\MemberModel;
use Contao\ModulePersonalData;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A3 (bullet 1): keeps tl_member.username in sync with tl_member.email
 * whenever the switch (A1) is on – for back end admin edits AND the front
 * end "personal data" module.
 *
 * Runs at LOW priority so it always receives the value already RETURNED by
 * EmailChangeListener::onSaveEmail() (priority 255), never Input::post():
 * during a pending front-end email change that returned value is still the
 * OLD address, so this listener sees "no effective change" and does nothing
 * – the real sync for that case happens in ConfirmEmailChangeController once
 * the change is confirmed (A3 bullet 3).
 *
 * For a back end admin edit there is no such interception, so $value here IS
 * the new address, and – if the username is eligible to follow (A2/A3) – is
 * written directly, because a fields.email.save callback can only return the
 * EMAIL value, never a sibling field.
 */
final class UsernameSyncListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_member', target: 'fields.email.save', priority: 0)]
    public function onSaveEmail(mixed $value, mixed $arg2 = null, mixed $arg3 = null): mixed
    {
        if (!$this->policy->isEnabled()) {
            return $value;
        }

        // Registration ($value, null): handled by RegistrationUsernameListener instead,
        // there is no persisted member yet to compare against here.
        [$memberId, $currentUsername, $oldEmail] = $this->currentState($arg2, $arg3);

        if (null === $memberId) {
            return $value;
        }

        if (!UsernameFollowRule::shouldFollow($currentUsername, $oldEmail)) {
            return $value; // A "fantasy" username stays untouched.
        }

        $newEmail = (string) $value;

        if ('' === $newEmail || 0 === strcasecmp($newEmail, $oldEmail)) {
            return $value; // No effective change (or the FE pending-case, see class docblock).
        }

        $reason = $this->usernamePolicy->evaluate($newEmail, $memberId);

        if (EligibilityReason::Eligible !== $reason) {
            // A2: reject the save outright – throwing from a save_callback is how Contao
            // surfaces a widget error (see the core's own "unique" check for the pattern).
            throw new \Exception($this->translator->trans('MSC.confirmEmailChange.usernameRejected', [], 'contao_default'));
        }

        $member = $this->framework->getAdapter(MemberModel::class)->findByPk($memberId);
        $member?->setRow(['username' => CanonicalUsername::normalize($newEmail)])->save();

        return $value;
    }

    /**
     * @return array{0: int|null, 1: string|null, 2: string}
     */
    private function currentState(mixed $arg2, mixed $arg3): array
    {
        if ($arg2 instanceof DataContainer) {
            // getCurrentRecord() (not the deprecated activeRecord) still reflects the row as
            // loaded, i.e. BEFORE this save – exactly the "old" state A3 needs to compare against.
            $record = $arg2->getCurrentRecord();

            if (null !== $record) {
                return [(int) $record['id'], $record['username'] ?? null, (string) ($record['email'] ?? '')];
            }
        }

        if ($arg2 instanceof FrontendUser && $arg3 instanceof ModulePersonalData) {
            return [(int) $arg2->id, $arg2->username, (string) $arg2->email];
        }

        return [null, null, ''];
    }
}
