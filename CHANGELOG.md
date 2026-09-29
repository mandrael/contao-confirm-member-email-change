# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.
Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

Datenbank-Update nötig (`contao:migrate`): neue Tabelle `tl_member_email_revoke`. Bis dahin scheitert
jeder Widerruf mit der allgemeinen Fehlerseite, und jede Anfrage eines angemeldeten Mitglieds loggt
einen Fehler.

### Sicherheit
- Widerruf: setzt jetzt auch Zwei-Faktor-Einrichtung, Backup-Codes, vertrauenswürdige Geräte und
  Passkeys (sofern die Contao-Version sie kennt) zurück. Ein Passkey, den der bisherige Inhaber angelegt hatte, meldete ihn
  sonst ohne Kennwort wieder an; ein von ihm eingerichteter zweiter Faktor sperrte das Mitglied aus.
- Widerruf: Eine Anfrage des bisherigen Inhabers, die ihre Sitzungsprüfung vor dem Widerruf
  bestanden hatte, konnte ihn danach aufheben: mit einem neuen Kennwort, einem „Angemeldet
  bleiben“ oder – bei jeder Anmeldung – über Contaos `User::save()`, das die vorher geladene
  Mitgliedszeile vollständig zurückschreibt. Ein Vermerk in `tl_member_email_revoke` (Zeitpunkt und
  Fingerabdruck des ersetzten Kennwort-Hashes) lässt das Ergebnis des Widerrufs jetzt am Ende jeder
  überlappenden Anfrage und bei jedem späteren Zurückschreiben erneut anwenden.
- Login mit abweichender Schreibweise: läuft erst nach Login-Drosselung und CSRF-Prüfung und sucht
  zuerst über den Index; die Suche ohne Index nur, wenn das nichts findet.
- Widerruf: Ist der Benutzername der wiederhergestellten Adresse vergeben (mit
  terminal42/contao-mailusername oder bei gleichzeitiger Vergabe), bleibt der bisherige stehen,
  statt den ganzen Widerruf am `UNIQUE`-Index scheitern zu lassen.
- Der Hinweis an die alte Adresse riet bei einer nicht selbst veranlassten Änderung zu „keine
  Aktion nötig“. Er rät jetzt, das Kennwort zu ändern, und kündigt den Widerrufslink an.

### Behoben
- Der stündliche Neuversand des Widerrufslinks scheiterte auf der Kommandozeile (`contao:cron`),
  wenn kein anderer Cronjob das Contao-Framework vorher gestartet hatte. Gefunden im
  ddev-Laufzeittest auf 5.3 und 5.7.
- Login mit abweichender Schreibweise findet jetzt auch einen gespeicherten Benutzernamen in
  gemischter Schreibweise (terminal42/contao-mailusername übernimmt die Adresse unverändert).
- Bestätigung: Ein beim Schreiben vergebener Benutzername zeigt die Seite „als Benutzername nicht
  verwendbar“ statt „ungültig“; der Link bleibt wie dort unverbraucht.
- Bestätigung: Für eine leere alte Adresse entsteht kein Sicherheitsanker mehr, der nur
  stündlich am Versand scheitern und einen späteren echten Anker blockieren würde.
- Profil: Die E-Mail-spezifische Meldung „existiert bereits“ ersetzt die allgemeine nicht mehr,
  wenn das Formular auch den Benutzernamen enthält.
- Eine im Profil angemeldete, aber nicht ausgestellte Änderung bleibt in langlebigen
  PHP-Prozessen (Worker) nicht mehr im Dienst hängen und wird nur für das eigene Mitglied
  ausgestellt.
- README: Die Aussage, bei eingeschaltetem Schalter trügen zwei Mitglieder nie dieselbe Adresse,
  ist eingeschränkt; der Verlust eines offenen Bestätigungslinks durch „Passwort ändern“ oder
  „Passwort vergessen“ ist dokumentiert.

## [1.1.2] - 2026-09-29

### Behoben
- Widerruf: gespeicherte „Angemeldet bleiben“-Anmeldungen des Mitglieds werden jetzt in derselben
  Transaktion gelöscht; scheitert das, wird der ganze Widerruf zurückgerollt.
  Unter Symfony 6.4 (Contao 5.3) hätte ein solches Cookie den bisherigen Inhaber des Kontos sonst
  ohne Kennwort wieder angemeldet.
- Widerruf: Trägt inzwischen ein anderes Mitglied die wiederherzustellende Adresse, bricht der
  Widerruf nicht mehr ab. Eine sofort nach der Übernahme angelegte Registrierung mit der alten
  Adresse konnte ihn sonst dauerhaft blockieren. Das Duplikat wird geloggt.
- Widerruf: Der Benutzername folgt der wiederhergestellten Adresse auch dann, wenn der Schalter
  „E-Mail als Benutzername“ inzwischen ausgeschaltet ist, sofern er noch die ersetzte Adresse war.
- Ein Widerruf zwischen Speichern und Abschluss eines Profilformulars konnte von einer danach noch
  ausgestellten Bestätigungsmail überholt werden. Der Link entsteht jetzt unter Zeilensperre und
  nur, solange die Adresse noch die Ausgangsadresse ist.
- Bestätigung: Die Prüfung, ob die neue Adresse inzwischen vergeben ist, sperrt jetzt. Zwei
  parallele Bestätigungen derselben Adresse konnten beide durchkommen. Die Garantie gilt unter der
  MySQL/MariaDB-Standardisolation `REPEATABLE READ`; unter `READ COMMITTED` bleibt ein kleines
  Fenster.
- Registrierung mit „E-Mail als Benutzername“: Ein Kennwort gleich der E-Mail-Adresse wird wie im
  Core abgelehnt (die Core-Prüfung greift nur beim geposteten Benutzernamen).
- Bestätigungs- und Widerrufsseite senden `Referrer-Policy: no-referrer`, damit der Link beim
  Klick auf „Zurück zur Website“ nicht weitergegeben wird.
- Index auf `tl_member.emailChangeAnchorHash` (Datenbank-Update nötig), damit die öffentliche
  Widerrufs-Route nicht die ganze Mitgliedertabelle durchsucht.
- `composer.json`: `symfony/password-hasher` als direkte Abhängigkeit.

## [1.1.1] - 2026-09-23

### Behoben
- Die Bestätigungsseite meldete jedes im selben Browser angemeldete Mitglied ab, auch ein anderes
  als das bestätigte. Jetzt nur noch das bestätigte Mitglied.
- Scheiterte die Benachrichtigung an die alte Adresse, entfiel die Abmeldung nach geändertem
  Benutzernamen. Beide Schritte sind jetzt unabhängig.
- Widerrufs- und Hinweis-Mail wurden nicht versendet, wenn die Administrator-E-Mail nur an der
  Root-Seite stand (Bestätigungsseite und Cron laufen außerhalb einer Contao-Seite). Der Absender
  wird jetzt auch von der Root-Seite gelesen, sofern die Installation nur eine Absender-Adresse an
  ihren Root-Seiten hat; bei mehreren Websites die globale Administrator-E-Mail setzen.
- Adressen mit Umlaut-Domain: Contao speichert sie als Punycode, die Anmeldung mit der lesbaren
  Schreibweise fand das Mitglied nicht. Login-Eingabe und Benutzername nutzen jetzt dieselbe Form.
- `member-email:sync-usernames --group=<id>` warnt, wenn zur Gruppe kein Mitglied gehört.
- `member-email:sync-usernames --group=<wert>` lehnt einen nicht numerischen Wert mit Fehlercode ab.
  Vorher galt er als Gruppe 0, traf kein Mitglied und endete scheinbar erfolgreich.
- Einstellungstext: Der Schalter „E-Mail als Benutzername“ nennt jetzt ausdrücklich, dass er nur
  Mitglieder betrifft, nicht Backend-Benutzer.
- `composer.json`: direkt benutzte Pakete `psr/log` und `symfony/event-dispatcher` als
  Abhängigkeit eingetragen (kamen bisher nur indirekt über Contao).
- README: Ablauf richtig beschrieben (der Bestätigungslink entsteht nach dem Speichern, nicht im
  Feld-Callback), Hinweis auf `contao:migrate` ergänzt, irreführende Begründung zum
  Versandfehler entfernt.

## [1.1.0] - 2026-09-21

### Hinzugefügt
- Schalter „E-Mail als Benutzername“ (Einstellungen, Standard aus): Der Benutzername ist dann
  zwingend die kleingeschriebene E-Mail-Adresse – bei Registrierung, „Persönliche Daten“,
  Backend-Bearbeitung und nach bestätigter Adressänderung. Das Feld ist nirgends mehr editierbar,
  das Login-Formular zeigt „E-Mail-Adresse“. Unzulässige Adressen (über 64 Zeichen, unerlaubte
  Zeichen, Kollision) werden beim Speichern abgelehnt.
- Befehl `member-email:sync-usernames` (Probelauf als Standard, `--force` schreibt, `--group=<id>`):
  gleicht den Bestand auf einmal ab. Gibt nur Mitglieds-IDs und Fallklassen aus, nie Adressen oder Namen.
- Anmeldung mit abweichender Groß-/Kleinschreibung der E-Mail, unabhängig vom Schalter.
- Widerrufslink: Nach einer bestätigten Adressänderung erhält die alte Adresse einen 14 Tage
  gültigen Link, der die Änderung rückgängig macht, das Kennwort ungültig setzt und offene Links
  entwertet. Nimmt der Mailer die Mail nicht an, wird derselbe Link stündlich erneut gesendet.
- Eine bestätigte Adressänderung entwertet offene „Kennwort vergessen“-Links des Mitglieds.

### Geändert
- Bestätigung und Widerruf laufen in einer Transaktion mit Zeilensperre auf das Mitglied.
- Die Bestätigungsseite sendet `Cache-Control: private, no-store` und `X-Robots-Tag: noindex`.
- Ist `terminal42/contao-mailusername` installiert, bleibt der eigene Schalter wirkungslos und die
  Einstellung weist darauf hin. `heimrichhannot/contao-email2username-bundle` wird nicht mehr
  unterstützt (läuft unter Contao 5 nicht).

### Behoben
- Fehlt die Administrator-E-Mail, führte das Anfordern einer Adressänderung zur Fehlerseite.

### Update
`contao:migrate` ausführen (fünf neue Spalten in `tl_member`). Vor `--force` ein
Datenbank-Backup anlegen.

## [1.0.0] - 2026-09-18

### Hinzugefügt
- Double-Opt-In-Bestätigung für die Änderung der E-Mail-Adresse eines Frontend-Mitglieds
  (`ModulePersonalData`): `fields.email.save`-Callback (Priorität 255) fängt die Änderung ab,
  Core-`OptIn`-Token + Bestätigungslink an die neue Adresse, Sicherheits-Benachrichtigung an
  die alte Adresse, `tl_member.email` ändert sich erst nach Bestätigung.
- Bestätigungs-Controller (`#[AsController]` + `#[Route]`): bestätigt den Token, schreibt die neue
  Adresse und zeigt eine **eigenständige Bestätigungsseite** (kein Redirect auf eine Theme-Seite,
  deren Module an der gerade geänderten Login-Identität scheitern könnten). Behandelt abgelaufene/
  bereits-bestätigte/ungültige Token und prüft die Eindeutigkeit der neuen Adresse zur Confirm-Zeit.
- Klarer FE-Hinweis im Profilmodul beim Speichern: auffällige hellgrüne Box
  („✓ Bestätigungslink an … gesendet …“ statt des irreführenden „gespeichert“).
- Ersetzt Contaos generische Unique-Meldung im FE-Profil durch eine E-Mail-spezifische
  („Diese E-Mail-Adresse existiert bereits.“ statt „Dieser Eintrag ist bereits vorhanden!“);
  das Backend behält die generische Meldung.
- Kompatibilität mit E-Mail-als-Username-Erweiterungen: Benutzername-Sync beim Bestätigen für
  `terminal42/contao-mailusername` (verbatim, Pflicht) bzw. `heimrichhannot/contao-email2username-bundle`
  (lowercase, kosmetisch). Ohne Erweiterung bleibt `username` unangetastet. Ändert sich der Benutzername,
  wird das Mitglied abgemeldet, damit es sich mit der neuen Adresse neu anmeldet.
- Deutsche und englische Sprachdateien.
