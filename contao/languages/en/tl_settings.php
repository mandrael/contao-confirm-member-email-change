<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'Email as username';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'Email as username',
    'Automatically sets the lowercased email address as the login name for new and empty usernames. An already different ("fantasy") username is NEVER overwritten automatically; migrating existing members is done via the "member-email:sync-usernames" console command (with --force).',
);
