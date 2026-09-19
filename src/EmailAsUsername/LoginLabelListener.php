<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Template;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A7: while the opt-in (A1) is on, the login name IS the email address (A2/A3),
 * so ModuleLogin's "Username" label would mislead a member who never had a
 * separate username. ModuleLogin::compile() sets `$this->Template->username =
 * $GLOBALS['TL_LANG']['MSC']['username'];` and mod_login*.html5 renders it as the
 * field's <label> (the "username" form FIELD NAME itself stays untouched – it is
 * what Symfony security's form-login authenticator expects).
 *
 * Only the TEMPLATE variable is overridden here, via the parseTemplate hook
 * (fires in Template::parse(), i.e. after ModuleLogin::compile() already set it,
 * right before rendering) – never the global MSC.username string, which is also
 * used on the back end login and the registration form.
 */
final class LoginLabelListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsHook('parseTemplate')]
    public function onParseTemplate(Template $template): void
    {
        if (!$this->policy->isEnabled() || !str_starts_with($template->getName(), 'mod_login')) {
            return;
        }

        $template->username = $this->translator->trans('MSC.confirmEmailChange.loginUsernameLabel', [], 'contao_default');
    }
}
