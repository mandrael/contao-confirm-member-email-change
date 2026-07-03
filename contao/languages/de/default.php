<?php

declare(strict_types=1);

/*
 * Sprachschlüssel für die E-Mail-Änderungs-Bestätigung.
 * %s in den *Text-Schlüsseln ist ein sprintf-Platzhalter (Link bzw. neue Adresse).
 */

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['subject'] = 'Bitte bestätigen Sie Ihre neue E-Mail-Adresse';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['text'] = "Sie haben für Ihr Konto eine neue E-Mail-Adresse angegeben.\n\nBitte bestätigen Sie die Änderung, indem Sie diesen Link öffnen:\n\n%s\n\nDer Link ist 24 Stunden gültig. Bis zur Bestätigung bleibt Ihre bisherige Adresse aktiv. Falls Sie das nicht waren, ignorieren Sie diese E-Mail.";

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['noticeSubject'] = 'Änderung Ihrer E-Mail-Adresse angefordert';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['noticeText'] = "Für Ihr Konto wurde eine Änderung der E-Mail-Adresse auf %s angefordert.\n\nDie Änderung wird erst wirksam, wenn sie über den an die neue Adresse gesendeten Link bestätigt wird. Falls Sie das nicht veranlasst haben, ist keine Aktion nötig – Ihre bisherige Adresse bleibt unverändert.";

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['pending'] = 'Wir haben einen Bestätigungslink an %s gesendet. Ihre E-Mail-Adresse wird erst nach dem Klick auf den Link geändert.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['success'] = 'Ihre neue E-Mail-Adresse wurde bestätigt und ist nun aktiv.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['expired'] = 'Dieser Bestätigungslink ist nicht mehr gültig. Bitte fordern Sie die Änderung erneut an.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['alreadyConfirmed'] = 'Diese E-Mail-Änderung wurde bereits bestätigt.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['invalid'] = 'Der Bestätigungslink ist ungültig.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['taken'] = 'Diese E-Mail-Adresse wird inzwischen bereits von einem anderen Konto verwendet.';

// Titles + link for the self-contained confirmation page
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['successTitle'] = 'E-Mail-Adresse bestätigt';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['errorTitle'] = 'Bestätigung nicht möglich';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['backToSite'] = 'Zur Startseite';

// Ersetzt Contaos generische Unique-Meldung im FE-Profil
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'] = 'Diese E-Mail-Adresse existiert bereits.';
