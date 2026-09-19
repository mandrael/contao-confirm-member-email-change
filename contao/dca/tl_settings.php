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
