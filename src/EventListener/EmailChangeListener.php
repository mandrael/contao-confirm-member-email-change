<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EventListener;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\Email;
use Contao\FrontendUser;
use Contao\ModulePersonalData;
use Contao\OptInModel;
use Symfony\Component\HttpFoundation\RequestStack;
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

    /**
     * Pending email change stashed by onSaveEmail() and consumed by onSubmit()
     * within the same request: [memberId, oldEmail, newEmail].
     *
     * @var array{0: int, 1: string, 2: string}|null
     */
    private ?array $pendingChange = null;

    public function __construct(
        private readonly OptIn $optIn,
        private readonly ContaoFramework $framework,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Intercepts the frontend personal-data email change.
     *
     * The same DCA save_callback also fires during registration (invoked as
     * ($value, null)) and in the back end (as ($value, DataContainer)); only
     * ModulePersonalData passes (FrontendUser, ModulePersonalData). We therefore
     * accept a broad signature and act ONLY for the personal-data case — leaving
     * the value untouched everywhere else (a narrow typed signature would fatal
     * on registration/back-end saves). Returning the OLD value suppresses the
     * core write (guarded by `if ($varValue !== $user->$field)`).
     */
    #[AsCallback(table: 'tl_member', target: 'fields.email.save', priority: 255)]
    public function onSaveEmail(mixed $value, mixed $user = null, mixed $module = null): mixed
    {
        if (!$user instanceof FrontendUser || !$module instanceof ModulePersonalData) {
            return $value;
        }

        $newEmail = (string) $value;
        $oldEmail = (string) $user->email;

        // No (effective) change → let the core handle it as usual.
        if ('' === $newEmail || 0 === strcasecmp($newEmail, $oldEmail)) {
            return $value;
        }

        // The core already rejected a non-unique new email before this callback
        // runs (ModulePersonalData adds a widget error and skips save_callbacks),
        // so $newEmail is free among committed members at this point.

        // Defer the actual token creation to onSubmit(): while processing the
        // password field, ModulePersonalData deletes ALL unconfirmed opt-ins of the
        // member. A token created here would be wiped when email and password change
        // in the same submit. The onsubmit callback runs after every field callback,
        // so a token created there survives the purge.
        $this->pendingChange = [(int) $user->id, $oldEmail, $newEmail];

        // Make ModulePersonalData's success message reflect the pending state.
        // The module renders MSC.savedData raw after a successful submit (and then
        // reloads), so overriding it here (this callback runs before that) replaces
        // the misleading "your data was saved" with a prominent light-green notice
        // that the email change still needs confirmation.
        $notice = \sprintf($this->trans('confirmEmailChange.pending'), $newEmail);
        $GLOBALS['TL_LANG']['MSC']['savedData'] = \sprintf(
            '<span style="display:block;margin:.5em 0;padding:14px 18px;background:#dcfce7;border:2px solid #22c55e;border-radius:8px;color:#14532d;font-weight:600;line-height:1.5;">✓ %s</span>',
            htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'),
        );

        // Keep the old address until the new one is confirmed → suppresses the write.
        return $oldEmail;
    }

    /**
     * Runs after all field callbacks on a successful personal-data submit (invoked
     * as ($user, $module); in the back end as ($dc)). Only here — once the password
     * field's opt-in purge has already run — do we create and send the confirmation
     * token, so it cannot be deleted within the same submit. onsubmit fires on every
     * successful submit regardless of which fields changed, so the email-only case
     * is covered too.
     */
    #[AsCallback(table: 'tl_member', target: 'config.onsubmit', priority: 255)]
    public function onSubmit(mixed $userOrDc = null, mixed $module = null): void
    {
        if (null === $this->pendingChange) {
            return;
        }

        [$memberId, $oldEmail, $newEmail] = $this->pendingChange;
        $this->pendingChange = null;

        // Replace any earlier unconfirmed email-change token of this member.
        $this->purgePendingTokens($memberId);

        $token = $this->optIn->create(self::PREFIX, $newEmail, ['tl_member' => [$memberId]]);

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
    }

    /**
     * On the front end, replace Contao's generic "this entry already exists" unique
     * error with an email-specific one — on the profile form the only editable unique
     * field is the email address. The back end keeps the generic message.
     */
    #[AsHook('loadLanguageFile')]
    public function onLoadLanguageFile(string $name): void
    {
        if ('default' !== $name) {
            return;
        }

        $request = $this->requestStack->getMainRequest();

        if (ContaoCoreBundle::SCOPE_FRONTEND !== $request?->attributes->get('_scope')) {
            return;
        }

        // Only for the personal-data module submit (FORM_SUBMIT = "tl_member_<id>").
        // Other frontend forms with a unique field — notably registration with a
        // duplicate username ("tl_registration_<id>") — must keep the generic message.
        if (!str_starts_with((string) $request->request->get('FORM_SUBMIT'), 'tl_member_')) {
            return;
        }

        if (isset($GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'])) {
            $GLOBALS['TL_LANG']['ERR']['unique'] = $GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'];
        }
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
