<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\Email;
use Contao\FrontendUser;
use Contao\Message;
use Contao\ModulePersonalData;
use Contao\OptInModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Intercepts a frontend member's email change in the personal-data module and
 * defers it behind a double-opt-in confirmation link.
 *
 * Runs at high priority (255) so that an active email-as-username extension
 * (terminal42/contao-mailusername, …) only ever sees the OLD email during a
 * pending change and never syncs a new username prematurely.
 */
class EmailChangeListener
{
    /**
     * OptIn prefix (max. 6 chars, see Contao\CoreBundle\OptIn\OptIn::create()).
     */
    private const PREFIX = 'email';

    public function __construct(
        private readonly OptIn $optIn,
        private readonly ContaoFramework $framework,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * In ModulePersonalData the save_callback is invoked as ($value, $user, $module)
     * — not with a DataContainer. Returning the OLD value suppresses the core write
     * (guarded by `if ($varValue !== $user->$field)`).
     */
    #[AsCallback(table: 'tl_member', target: 'fields.email.save', priority: 255)]
    public function onSaveEmail(mixed $value, FrontendUser $user, ModulePersonalData $module): mixed
    {
        $newEmail = (string) $value;
        $oldEmail = (string) $user->email;

        // No (effective) change → let the core handle it as usual.
        if ('' === $newEmail || 0 === strcasecmp($newEmail, $oldEmail)) {
            return $value;
        }

        // The core already rejected a non-unique new email before this callback
        // runs (ModulePersonalData adds a widget error and skips save_callbacks),
        // so $newEmail is free among committed members at this point.

        // Replace any earlier unconfirmed email-change token of this member.
        $this->purgePendingTokens((int) $user->id);

        $token = $this->optIn->create(self::PREFIX, $newEmail, ['tl_member' => [(int) $user->id]]);

        $url = $this->urlGenerator->generate(
            'mandrael_confirm_member_email_change',
            ['token' => $token->getIdentifier()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $token->send(
            $this->trans('confirmEmailChange.subject'),
            \sprintf($this->trans('confirmEmailChange.text'), $url),
        );

        // Security: tell the OLD address that a change was requested.
        $this->notifyOldAddress($oldEmail, $newEmail);

        $this->framework->getAdapter(Message::class)->addConfirmation(
            \sprintf($this->trans('confirmEmailChange.pending'), $newEmail),
        );

        // Keep the old address until the new one is confirmed → suppresses the write.
        return $oldEmail;
    }

    private function purgePendingTokens(int $memberId): void
    {
        $tokens = $this->framework
            ->getAdapter(OptInModel::class)
            ->findUnconfirmedByRelatedTableAndId('tl_member', $memberId)
        ;

        foreach ($tokens ?? [] as $model) {
            if (str_starts_with((string) $model->token, self::PREFIX.'-')) {
                $model->delete();
            }
        }
    }

    private function notifyOldAddress(string $oldEmail, string $newEmail): void
    {
        $email = $this->framework->createInstance(Email::class);
        $email->subject = $this->trans('confirmEmailChange.noticeSubject');
        $email->text = \sprintf($this->trans('confirmEmailChange.noticeText'), $newEmail);
        $email->from = $GLOBALS['TL_ADMIN_EMAIL'] ?? null;
        $email->fromName = $GLOBALS['TL_ADMIN_NAME'] ?? null;
        $email->sendTo($oldEmail);
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.'.$key, [], 'contao_default');
    }
}
