<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\MemberModel;
use Contao\ModulePersonalData;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A3 (bullet 1): keeps tl_member.username in sync with tl_member.email whenever the
 * switch (A1) is on. The username always follows the email; there is no "fantasy"
 * (deliberately chosen) username to protect anymore.
 *
 * Deliberately split in two halves:
 *
 * - onSaveEmail() is the fields.email.save callback and only ever VALIDATES. It runs at
 *   priority 256, i.e. BEFORE EmailChangeListener (255) swaps a pending front-end change
 *   back to the old address, so it always sees the address the member really typed – in
 *   the back end, in "personal data" AND during registration. An ineligible NEW address
 *   is thrown back, which is how all three call sites surface a widget error (DC_Table,
 *   ModulePersonalData and ModuleRegistration each catch \Exception from a save
 *   callback); during registration the row is then never inserted at all. An UNCHANGED
 *   ineligible address does NOT throw – a legacy member has to stay editable.
 *
 * - onSubmitMember() is the config.onsubmit callback and does the WRITING, back end only.
 *   It cannot live in the field callback: DC_Table runs field save callbacks BEFORE the
 *   email uniqueness check (core 5.3 DC_Table.php:3351-3370), so a username written there
 *   would outlive an email change that is rejected a moment later. onsubmit runs after
 *   the row was written and after the current-record cache was dropped, so
 *   getCurrentRecord() returns the address that actually got saved. The front end
 *   deliberately does not write: there the address only moves once the change is
 *   confirmed (ConfirmEmailChangeController), and "email unchanged" is indistinguishable
 *   from a pending change.
 */
final class UsernameSyncListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    #[AsCallback(table: 'tl_member', target: 'fields.email.save', priority: 256)]
    public function onSaveEmail(mixed $value, mixed $arg2 = null, mixed $arg3 = null): mixed
    {
        if (!$this->policy->isEnabled()) {
            return $value;
        }

        $newEmail = trim((string) $value);

        if ('' === $newEmail) {
            return $value;
        }

        [$memberId, $oldEmail] = $this->currentState($arg2, $arg3);

        if ($newEmail === trim($oldEmail)) {
            return $value; // Unchanged – not a request to change the login name either.
        }

        if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($newEmail, $memberId)) {
            // A2: reject the save outright – throwing from a save_callback is how Contao
            // surfaces a widget error (see the core's own "unique" check for the pattern).
            throw new \Exception($this->translator->trans('MSC.confirmEmailChange.usernameRejected', [], 'contao_default'));
        }

        return $value;
    }

    #[AsCallback(table: 'tl_member', target: 'config.onsubmit', priority: 0)]
    public function onSubmitMember(mixed $dc = null): void
    {
        // ModulePersonalData passes (FrontendUser, ModulePersonalData) here; only the
        // back end writes, see the class docblock.
        if (!$dc instanceof DataContainer || !$this->policy->isEnabled()) {
            return;
        }

        $record = $dc->getCurrentRecord();

        if (null === $record || !isset($record['id'], $record['email'])) {
            return; // A failed read must never end up writing a default value.
        }

        $memberId = (int) $record['id'];
        $email = trim((string) $record['email']);

        if ('' === $email) {
            return;
        }

        $canonical = CanonicalUsername::normalize($email);

        // Exact match against the canonical form, NOT strcasecmp: "Anna@example.com" as a
        // login name is not in sync, the rule demands the lower-cased address.
        if ($canonical === (string) ($record['username'] ?? '')) {
            return;
        }

        if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($email, $memberId)) {
            // Only reachable for an address that was already stored before the switch went
            // on – onSaveEmail() rejects every CHANGED ineligible address. Saving the
            // member must not fail over it, so the login name simply stays behind.
            $this->logger?->warning(\sprintf('Member ID %d keeps its previous login name: the stored address is not eligible as one.', $memberId));

            return;
        }

        $member = $this->framework->getAdapter(MemberModel::class)->findByPk($memberId);

        if (null === $member) {
            return;
        }

        // NOT setRow(): that replaces the whole row and marks nothing as modified, so the
        // following save() would write nothing and strip the model of its id (core 5.3
        // Model.php:376-393,547-568).
        $member->username = $canonical;
        $member->save();
    }

    /**
     * The member id and the address as currently STORED, i.e. before this save.
     *
     * @return array{0: int|null, 1: string}
     */
    private function currentState(mixed $arg2, mixed $arg3): array
    {
        if ($arg2 instanceof DataContainer) {
            $record = $arg2->getCurrentRecord();

            if (null !== $record) {
                return [(int) $record['id'], (string) ($record['email'] ?? '')];
            }
        }

        if ($arg2 instanceof FrontendUser && $arg3 instanceof ModulePersonalData) {
            return [(int) $arg2->id, (string) $arg2->email];
        }

        // Registration: no persisted member yet, so nothing to exclude from the
        // collision check and no old address to compare against.
        return [null, ''];
    }
}
