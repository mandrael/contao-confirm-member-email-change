<?php

declare(strict_types=1);

/*
 * A1: opt-in switch "email as username" (Contao\Config, no schema change –
 * tl_settings is a DC_File config array, not a database table).
 */

$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'] = str_replace(
    '{security_legend:hide}',
    '{email_as_username_legend},memberEmailAsUsername;{security_legend:hide}',
    $GLOBALS['TL_DCA']['tl_settings']['palettes']['default'],
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['memberEmailAsUsername'] = array
(
    'inputType' => 'checkbox',
    'eval'      => array('tl_class' => 'clr'),
);

// terminal42/contao-mailusername owns tl_member.username as well, so the switch stays
// without effect while it is installed (EmailAsUsernamePolicy::isEnabled()). Say so at
// the field rather than silently doing nothing. Deliberately no composer "conflict":
// that would lock existing installs of the published package out of an update.
if (Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy::isTerminal42Active()) {
    $GLOBALS['TL_DCA']['tl_settings']['fields']['memberEmailAsUsername']['label'] = &$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsernameBlocked'];
}
