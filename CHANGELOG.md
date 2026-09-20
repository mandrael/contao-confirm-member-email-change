# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.
Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.1.0] - 2026-09-21

### Hinzugefügt
- Schalter „E-Mail als Benutzername" (Einstellungen, Standard aus): Der Benutzername ist dann
  zwingend die kleingeschriebene E-Mail-Adresse – bei Registrierung, „Persönliche Daten",
  Backend-Bearbeitung und nach bestätigter Adressänderung. Das Feld ist nirgends mehr editierbar,
  das Login-Formular zeigt „E-Mail-Adresse". Unzulässige Adressen (über 64 Zeichen, unerlaubte
  Zeichen, Kollision) werden beim Speichern abgelehnt.
- Befehl `member-email:sync-usernames` (Probelauf als Standard, `--force` schreibt, `--group=<id>`):
  gleicht den Bestand auf einmal ab. Gibt nur Mitglieds-IDs aus.
- Anmeldung mit abweichender Groß-/Kleinschreibung der E-Mail, unabhängig vom Schalter.
- Widerrufslink: Nach einer bestätigten Adressänderung erhält die alte Adresse einen 14 Tage
  gültigen Link, der die Änderung rückgängig macht, das Kennwort ungültig setzt und offene Links
  entwertet. Scheitert der Versand, wird derselbe Link stündlich erneut gesendet.
- Eine bestätigte Adressänderung entwertet offene „Kennwort vergessen"-Links des Mitglieds.

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
  („✓ Bestätigungslink an … gesendet …" statt des irreführenden „gespeichert").
- Ersetzt Contaos generische Unique-Meldung im FE-Profil durch eine E-Mail-spezifische
  („Diese E-Mail-Adresse existiert bereits." statt „Dieser Eintrag ist bereits vorhanden!");
  das Backend behält die generische Meldung.
- Kompatibilität mit E-Mail-als-Username-Erweiterungen: Benutzername-Sync beim Bestätigen für
  `terminal42/contao-mailusername` (verbatim, Pflicht) bzw. `heimrichhannot/contao-email2username-bundle`
  (lowercase, kosmetisch). Ohne Erweiterung bleibt `username` unangetastet. Ändert sich der Benutzername,
  wird das Mitglied abgemeldet, damit es sich mit der neuen Adresse neu anmeldet.
- Deutsche und englische Sprachdateien.
