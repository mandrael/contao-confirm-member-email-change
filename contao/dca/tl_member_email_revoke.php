<?php

declare(strict_types=1);

/*
 * One row per recent revoke, read by RevokeFenceListener. Deliberately NOT a tl_member
 * column: Contao's User::save() writes back the whole row it loaded (login, password
 * upgrade, two-factor), so a login that loaded the member before the revoke committed
 * would reset such a column together with everything else. SQL-only, one row per member;
 * the daily purge cron deletes rows older than RevokeFenceListener::RETENTION.
 */
$GLOBALS['TL_DCA']['tl_member_email_revoke'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        // tl_member.id
        'pid' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        // time of the commit (written as the last statement before it)
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        // the restored address and the login name the revoke left
        'email' => ['sql' => "varchar(255) NOT NULL default ''"],
        'username' => ['sql' => 'varchar(64) BINARY NULL'],
        // sha256 of the password hash the revoke replaced: a write-back of the old row
        // brings exactly that hash back, however late it happens
        'passwordDigest' => ['sql' => "char(64) NOT NULL default ''"],
    ],
];
