<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'E-Mail als Benutzername';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'E-Mail als Benutzername',
    'Der Benutzername ist dann immer die kleingeschriebene E-Mail-Adresse und im Backend/Frontend nicht mehr frei editierbar – ein bereits abweichender Benutzername wird beim nächsten Speichern des Mitglieds korrigiert. Für den gesamten Bestand auf einmal erledigt das der Konsolenbefehl „member-email:sync-usernames" (mit --force).',
);

// Wird statt der normalen Beschriftung verwendet, solange terminal42/contao-mailusername
// installiert ist (siehe contao/dca/tl_settings.php).
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsernameBlocked'] = array(
    'E-Mail als Benutzername (wirkungslos)',
    'Dieser Schalter bleibt ohne Wirkung, solange die Erweiterung terminal42/contao-mailusername installiert ist: Beide schreiben tl_member.username. Entfernen Sie eine der beiden Erweiterungen.',
);
