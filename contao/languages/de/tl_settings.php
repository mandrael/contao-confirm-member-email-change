<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_settings']['email_as_username_legend'] = 'Mitglieder: E-Mail als Benutzername';
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsername'] = array(
    'E-Mail-Adresse als Benutzername der Mitglieder',
    'Gilt nur für Mitglieder (Frontend-Login), nicht für Backend-Benutzer. Der Benutzername eines Mitglieds ist dann immer seine kleingeschriebene E-Mail-Adresse und lässt sich weder in der Mitgliederverwaltung noch im Frontend ändern. Ein abweichender Benutzername wird beim nächsten Speichern des Mitglieds angeglichen. Für den gesamten Bestand auf einmal erledigt das der Konsolenbefehl „member-email:sync-usernames" (mit --force).',
);

// Wird statt der normalen Beschriftung verwendet, solange terminal42/contao-mailusername
// installiert ist (siehe contao/dca/tl_settings.php).
$GLOBALS['TL_LANG']['tl_settings']['memberEmailAsUsernameBlocked'] = array(
    'E-Mail-Adresse als Benutzername der Mitglieder (wirkungslos)',
    'Dieser Schalter bleibt ohne Wirkung, solange die Erweiterung terminal42/contao-mailusername installiert ist: Beide schreiben tl_member.username. Entfernen Sie eine der beiden Erweiterungen.',
);
