<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'E-Mail als Benutzername';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'E-Mail als Benutzername',
    'Stellt für neue und leere Benutzernamen automatisch die kleingeschriebene E-Mail-Adresse als Login-Namen ein. Ein bereits abweichender („Fantasie"-)Benutzername wird dabei NIE automatisch überschrieben; das Umstellen bestehender Mitglieder erledigt der Konsolenbefehl „member-email:sync-usernames" (mit --force).',
);
