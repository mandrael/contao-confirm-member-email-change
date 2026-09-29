<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\Input;
use Contao\MemberModel;
use Contao\System;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

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
 *
 * Overwrites a pre-filled username too, not just an empty one -
 * "username IS the email" while the switch is on has no exception for a value the
 * registrant (or a legacy module config) happened to submit. In practice this rarely
 * fires: UsernameFieldLockListener already keeps the field from being posted at all.
 */
final class RegistrationUsernameListener
{
    public function __construct(
        private readonly EmailAsUsernamePolicy $policy,
        private readonly UsernamePolicy $usernamePolicy,
        private readonly ContaoFramework $framework,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /**
     * ModuleRegistration refuses a password equal to the POSTED username, and the
     * username field is not posted while the switch is on – the login name only comes
     * from the email after the insert. So the core rule would never fire; apply it to
     * the address instead. Only the registration call ($value, null): the value is the
     * hash the password widget already produced.
     */
    #[AsCallback(table: 'tl_member', target: 'fields.password.save')]
    public function onSavePassword(mixed $value, mixed $user = null, mixed $module = null): mixed
    {
        if (null !== $user || !\is_string($value) || '' === $value || !$this->policy->isEnabled()) {
            return $value;
        }

        $email = $this->framework->getAdapter(Input::class)->post('email');

        if (!\is_string($email) || '' === trim($email)) {
            return $value;
        }

        $hasher = $this->passwordHasherFactory->getPasswordHasher(FrontendUser::class);

        foreach (array_unique([$email, CanonicalUsername::normalize($email)]) as $candidate) {
            if ($hasher->verify($value, $candidate)) {
                throw new \RuntimeException($GLOBALS['TL_LANG']['ERR']['passwordName'] ?? 'The password must not be the same as the username.');
            }
        }

        return $value;
    }

    #[AsHook('createNewUser')]
    public function onCreateNewUser(int $insertId, array $arrData): void
    {
        if (!$this->policy->isEnabled()) {
            return;
        }

        $email = (string) ($arrData['email'] ?? '');

        if (EligibilityReason::Eligible !== $this->usernamePolicy->evaluate($email, $insertId)) {
            // UsernameSyncListener::onSaveEmail already rejected an ineligible address
            // before the row was inserted, so getting here means the address became
            // ineligible in between (a parallel registration took it). Leave the
            // username empty rather than guess; member-email:sync-usernames fixes it.
            $this->logger?->warning(\sprintf('Member ID %d was registered without a login name: the address is not eligible as one.', $insertId));

            return;
        }

        $member = $this->framework->getAdapter(MemberModel::class)->findByPk($insertId);

        if (null === $member) {
            $this->logger?->warning(\sprintf('Member ID %d disappeared before its login name could be set.', $insertId));

            return;
        }

        // NOT setRow(): that replaces the whole row and marks nothing as modified, so
        // the following save() would write nothing at all and strip the model of its
        // id (Contao Model::setRow()/save(), core 5.3 Model.php:376-393,547-568).
        $member->username = CanonicalUsername::normalize($email);
        $member->save();
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
