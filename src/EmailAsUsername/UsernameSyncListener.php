<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\ModulePersonalData;
use Doctrine\DBAL\Connection;
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
 * - onSubmitMember() is the config.onsubmit callback and does the WRITING, for both the
 *   back end and, since Runde 2 Befund 4(a), the front-end "personal data" save. It
 *   cannot live in the field callback: DC_Table runs field save callbacks BEFORE the
 *   email uniqueness check (core 5.3 DC_Table.php:3351-3370), so a username written there
 *   would outlive an email change that is rejected a moment later. onsubmit runs after
 *   the row was written and after the current-record cache was dropped, so
 *   getCurrentRecord() returns the address that actually got saved. On the front end it
 *   runs AFTER EmailChangeListener::onSubmit() (priority 255 vs. 0, descending), whose
 *   own save_callback already suppressed the write of any pending new address – so
 *   $user->email here is always the address that is really committed at this moment,
 *   never a pending one (Runde 2, Befund 4(a) resolved that way, not by writing early).
 *
 * Both write paths go through a single UPDATE conditioned on `id = ? AND email = ?`
 * using the address just read (Runde 2, Befund 3, blockierend): a Model::save() call
 * here would write unconditionally and could overwrite a login name set moments earlier
 * by a confirmed or revoked change that raced this very save. Zero affected rows simply
 * means the address changed in between – nothing to do, the next save (or
 * member-email:sync-usernames) catches it.
 */
final class UsernameSyncListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly TranslatorInterface $translator,
        private readonly Connection $connection,
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
    public function onSubmitMember(mixed $dc = null, mixed $module = null): void
    {
        if (!$this->policy->isEnabled()) {
            return;
        }

        if ($dc instanceof DataContainer) {
            $record = $dc->getCurrentRecord();

            if (null === $record || !isset($record['id'], $record['email'])) {
                return; // A failed read must never end up writing a default value.
            }

            $this->syncUsername((int) $record['id'], (string) $record['email'], (string) ($record['username'] ?? ''));

            return;
        }

        if ($dc instanceof FrontendUser && $module instanceof ModulePersonalData) {
            $this->syncUsername((int) $dc->id, (string) $dc->email, (string) $dc->username);
        }
    }

    /**
     * $email is the address just read from the current save (back end: getCurrentRecord()
     * after the write; front end: $user->email, which EmailChangeListener already pinned
     * to the saved value even while a change is pending) – the conditional UPDATE below
     * writes only if that exact address still stands.
     */
    private function syncUsername(int $memberId, string $email, string $currentUsername): void
    {
        $email = trim($email);

        if ('' === $email) {
            return;
        }

        $canonical = CanonicalUsername::normalize($email);

        // Exact match against the canonical form, NOT strcasecmp: "Anna@example.com" as a
        // login name is not in sync, the rule demands the lower-cased address.
        if ($canonical === $currentUsername) {
            return;
        }

        if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($email, $memberId)) {
            // Runde 2, Befund 4: the other of the two DELIBERATE exceptions to "username IS
            // the email" (the other is RevokeEmailChangeController's colliding-restore
            // case). Only reachable for an address that was already stored before the switch
            // went on – onSaveEmail() rejects every CHANGED ineligible address. Saving the
            // member must not fail over it, so the login name simply stays behind.
            $this->logger?->warning(\sprintf('Member ID %d keeps its previous login name: the stored address is not eligible as one.', $memberId));

            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_member SET username = ?, tstamp = ? WHERE id = ? AND email = ?',
            [$canonical, time(), $memberId, $email],
        );
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
