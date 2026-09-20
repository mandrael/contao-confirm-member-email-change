<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'Members: email as username';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'Email address as member username',
    'Applies to members (front end login) only, not to back end users. A member\'s username then always is their lowercased email address and can be changed neither in the member management nor in the front end. A differing username is aligned the next time the member is saved. To fix the whole existing database in one go, use the "member-email:sync-usernames" console command (with --force).',
);

// Used instead of the regular label while terminal42/contao-mailusername is installed
// (see contao/dca/tl_settings.php).
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsernameBlocked'] = array(
    'Email address as member username (without effect)',
    'This switch stays without effect while the terminal42/contao-mailusername extension is installed: both write tl_member.username. Remove one of the two extensions.',
);
