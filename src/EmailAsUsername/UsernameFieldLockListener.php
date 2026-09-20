<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;

/**
 * A6: while the opt-in (A1) is on, tl_member.username is entirely computed from
 * the email (A2/A3), so nobody may type a conflicting value into it anymore –
 * neither a back end admin nor a member through a front end form that still has
 * "username" configured as an editable field (from before the switch was turned
 * on; RegistrationUsernameListener::getEditableMemberProperties() only keeps NEW
 * module configs from picking it, it cannot strip an existing one).
 *
 * All three places that could render/save the field share exactly one gate –
 * "does the widget class resolved from inputType via TL_FFL/BE_FFL exist?":
 *  - back end edit, Contao\classes\DataContainer::row(): `$strClass =
 *    $GLOBALS['BE_FFL'][$arrData['inputType'] ?? ''] ?? null; if
 *    (!class_exists($strClass)) { return ''; }` - no widget is built, so nothing
 *    is ever posted or saved for it either.
 *  - front end "personal data", ModulePersonalData::compile(): the same
 *    class_exists() gate (it ALSO checks eval.feEditable, but that alone would
 *    not cover registration below).
 *  - front end "registration", ModuleRegistration::compile(): the same
 *    class_exists() gate again, WITHOUT any eval.feEditable check.
 *
 * Pointing inputType at a key registered here (mapped to '', never a real
 * class) fails that gate everywhere in one move. Simply removing/blanking
 * inputType would do the same, but a lookup miss on TL_FFL/BE_FFL returns
 * null, and passing null to class_exists() is a deprecated call in PHP 8.1+ -
 * registering the key avoids that.
 */
final class UsernameFieldLockListener
{
    private const LOCKED_INPUT_TYPE = 'mandraelUsernameLocked';

    public function __construct(private readonly EmailAsUsernamePolicy $policy)
    {
    }

    #[AsHook('loadDataContainer')]
    public function onLoadDataContainer(string $table): void
    {
        if ('tl_member' !== $table || !$this->policy->isEnabled()) {
            return;
        }

        $GLOBALS['BE_FFL'][self::LOCKED_INPUT_TYPE] ??= '';
        $GLOBALS['TL_FFL'][self::LOCKED_INPUT_TYPE] ??= '';

        $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType'] = self::LOCKED_INPUT_TYPE;
    }
}
