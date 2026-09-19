<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\MemberModel;
use Contao\System;

/**
 * A3 (bullet 2): registration. ModuleRegistration inserts the new row itself
 * and does not honour DCA save_callbacks for it (see the core source), so
 * this hooks in afterwards – the same pattern terminal42/contao-mailusername
 * uses (recordUsername). Only ever fills an EMPTY username: with the field
 * locked (see UsernameFieldLockListener), it always is.
 *
 * Also removes "username" from the editable-fields option list of tl_module
 * (personalData/registration) while the switch is on, so new module configs
 * cannot pick it in the first place – purely cosmetic now that
 * UsernameFieldLockListener stops an ALREADY configured module from
 * rendering/saving it either way, but still saves the admin from picking a
 * field that would do nothing.
 */
final class RegistrationUsernameListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsHook('createNewUser')]
    public function onCreateNewUser(int $insertId, array $arrData): void
    {
        if (!$this->policy->isEnabled()) {
            return;
        }

        if (!empty($arrData['username'] ?? null)) {
            return; // The registrant (or the module config) already set one – leave it.
        }

        $email = (string) ($arrData['email'] ?? '');

        if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($email, $insertId)) {
            return; // Not eligible as a username → stays empty, fixable later via the console command.
        }

        $member = $this->framework->getAdapter(MemberModel::class)->findByPk($insertId);
        $member?->setRow(['username' => CanonicalUsername::normalize($email)])->save();
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_module', target: 'fields.editable.options', priority: 1)]
    public function getEditableMemberProperties(DataContainer $dc): array
    {
        $options = $this->framework->getAdapter(System::class)->importStatic('tl_module')->getEditableMemberProperties();

        if ($this->policy->isEnabled()) {
            unset($options['username']);
        }

        return $options;
    }
}
