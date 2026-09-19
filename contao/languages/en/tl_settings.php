<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'Email as username';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'Email as username',
    'The username then always is the lowercased email address and can no longer be edited freely in the back end/front end - an already different username is corrected the next time the member is saved. To fix the whole existing database in one go, use the "member-email:sync-usernames" console command (with --force).',
);
