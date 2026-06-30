# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.
Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt
- Double-Opt-In-Bestätigung für die Änderung der E-Mail-Adresse eines Frontend-Mitglieds
  (`ModulePersonalData`): `fields.email.save`-Callback (Priorität 255) fängt die Änderung ab,
  Core-`OptIn`-Token + Bestätigungslink an die neue Adresse, Sicherheits-Benachrichtigung an
  die alte Adresse, `tl_member.email` ändert sich erst nach Bestätigung.
- Bestätigungs-Controller (`#[AsController]` + `#[Route]`) inkl. abgelaufener/bereits-bestätigter
  Token-Behandlung und Confirm-Zeit-Unique-Prüfung der neuen Adresse.
- Kompatibilität mit E-Mail-als-Username-Erweiterungen: Benutzername-Sync beim Bestätigen für
  `terminal42/contao-mailusername` (verbatim, Pflicht) bzw. `heimrichhannot/contao-email2username-bundle`
  (lowercase, kosmetisch). Ohne Erweiterung bleibt `username` unangetastet.
- Optionale `jump_to`-Zielseite für die Erfolgs-/Fehlermeldung.
- Deutsche und englische Sprachdateien.
