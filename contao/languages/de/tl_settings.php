<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'E-Mail als Benutzername';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'E-Mail als Benutzername',
    'Der Benutzername ist dann immer die kleingeschriebene E-Mail-Adresse und im Backend/Frontend nicht mehr frei editierbar – ein bereits abweichender Benutzername wird beim nächsten Speichern des Mitglieds korrigiert. Für den gesamten Bestand auf einmal erledigt das der Konsolenbefehl „member-email:sync-usernames" (mit --force).',
);
