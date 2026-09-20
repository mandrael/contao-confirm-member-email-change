<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'Email as username';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'Email as username',
    'The username then always is the lowercased email address and can no longer be edited freely in the back end/front end - an already different username is corrected the next time the member is saved. To fix the whole existing database in one go, use the "member-email:sync-usernames" console command (with --force).',
);

// Used instead of the regular label while terminal42/contao-mailusername is installed
// (see contao/dca/tl_settings.php).
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsernameBlocked'] = array(
    'Email as username (without effect)',
    'This switch stays without effect while the terminal42/contao-mailusername extension is installed: both write tl_member.username. Remove one of the two extensions.',
);
