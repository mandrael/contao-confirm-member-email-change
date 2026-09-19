# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.
Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.1.0] - Unveröffentlicht

### Hinzugefügt
- Opt-in „E-Mail als Benutzername" (`tl_settings.memberEmailAsUsername`, Standard aus): Login-Name
  = kleingeschriebene, getrimmte E-Mail-Adresse, zulässig nur bei höchstens 64 Zeichen, Contaos
  `extnd`-Zeichenprüfung und ohne Kollision mit einem anderen Mitglied; sonst wird das Speichern der
  E-Mail abgelehnt. Der Benutzername folgt **immer** der aktuellen Adresse – bei Registrierung, im
  Self-Service-Profil, bei Backend-Bearbeitung und nach bestätigter E-Mail-Änderung; ein bereits
  abweichender Benutzername wird beim nächsten Speichern korrigiert (Ausnahme: eine noch
  unbestätigte E-Mail-Änderung – dort bleibt er bis zur Bestätigung an der alten Adresse). Das
  Benutzername-Feld ist dabei weder im Backend noch in einem Frontend-Modul editierbar, auch nicht
  bei einem Modul, das „username" noch aus der Zeit vor dem Einschalten als editierbares Feld
  konfiguriert hat. Das Login-Formular zeigt statt „Benutzername" die Beschriftung
  „E-Mail-Adresse" (nur die Beschriftung, das Formularfeld heißt technisch weiterhin `username`).
- Login-Listener auf `CheckPassportEvent` (nur Frontend-Firewall, nur mit „@" in der Eingabe):
  sucht bei fehlendem exaktem Treffer zusätzlich die kleingeschriebene Variante, ohne einen
  bestehenden, anders geschriebenen Benutzernamen zu verdecken.
- Konsolenbefehl `member-email:sync-usernames` (Probelauf als Standard, `--force` zum Schreiben,
  `--group=<id>` zur Eingrenzung): gleicht den gesamten Mitgliederbestand auf einmal ab, statt auf
  das nächste Speichern jedes einzelnen Mitglieds zu warten. Gibt ausschließlich Mitglieds-IDs und
  Fallklassen aus, nie E-Mail-Adressen oder Namen.
- Widerruft beim bestätigten E-Mail-Wechsel offene Core-Kennwort-Token (Präfix `pw`) des Mitglieds,
  damit ein alter Kennwort-Link nicht mehr auf die neue Adresse zielt.
- Neues `tl_settings`-Feld samt Palette und deutscher/englischer Sprachdatei.
- **Sicherheitsanker:** Eine bestätigte E-Mail-Änderung legt an `tl_member` einen Widerrufs-Anker
  an (SHA-256-Hash des Tokens, alte Adresse, Ablaufzeit; neue SQL-only-Felder
  `emailChangeAnchorHash`/`emailChangeAnchorEmail`/`emailChangeAnchorExpires`, kein Backend-Feld)
  und schickt der alten Adresse eine zweite Mail mit einem 14 Tage gültigen Widerrufslink.
  Ketten-Regel: Existiert bereits ein gültiger Anker, bleibt er beim nächsten bestätigten Wechsel
  unverändert (der älteste gewinnt); die alte Adresse dieses zweiten Wechsels bekommt die
  Benachrichtigung dann ohne Link. Einlösen über eine neue Route (`GET` zeigt nur ein
  Bestätigungsformular, `POST` mit Contaos Formular-Token führt unter Zeilensperre und
  Transaktion aus): stellt die alte Adresse wieder her (sofern nicht inzwischen anderweitig
  vergeben), führt den Benutzernamen nach der bestehenden Folgeregel zurück, macht das Kennwort
  über einen für alle Passwort-Hasher unverifizierbaren Wert ungültig (ohne `login` anzutasten),
  löscht alle unbestätigten Opt-in-Token des Mitglieds und meldet eine laufende Sitzung ab. Jeder
  Fehlschlag zeigt dieselbe allgemeine Meldung. Ein täglicher Cron räumt abgelaufene Anker auf.

### Geändert
- `terminal42/contao-mailusername` bleibt als Rückfall unterstützt, solange der eingebaute Opt-in
  ausgeschaltet ist. `heimrichhannot/contao-email2username-bundle` wird nicht mehr unterstützt (nutzt
  den in Contao 5 entfernten `importUser`-Hook und funktioniert dort nicht).
- Ist `terminal42/contao-mailusername` installiert, liefert der eigene Schalter zur Laufzeit immer
  „aus" und das Einstellungsfeld weist darauf hin. Bewusst kein `conflict` in der `composer.json`:
  der würde bestehende Installationen des veröffentlichten Pakets vom Update aussperren.

### Behoben
- **Der Benutzername wurde in Backend und Registrierung tatsächlich nie geschrieben.** Beide Wege
  nutzten `Model::setRow([...])->save()`; `setRow()` ersetzt die ganze Zeile und markiert nichts als
  geändert, `save()` schrieb deshalb nichts und das Modell verlor im laufenden Request seine ID
  (Core 5.3 `Model.php:376-393,547-568`). Jetzt wird die Eigenschaft gesetzt und gespeichert. Im
  Backend wandert der Schreibvorgang zusätzlich aus dem Feld-Callback in `config.onsubmit`: der
  Feld-Callback läuft in `DC_Table` **vor** der Eindeutigkeitsprüfung der E-Mail, ein dort
  geschriebener Benutzername hätte eine anschließend abgelehnte Adresse überlebt. Er prüft weiter,
  er schreibt nur nicht mehr.
- „Benutzername = E-Mail" gilt jetzt lückenlos: Eine unzulässige Adresse wird in der Registrierung
  abgewiesen, bevor das Mitglied entsteht, im Frontend schon beim Anfordern der Änderung, und eine
  erst nachträglich unzulässig gewordene Adresse wird nicht bestätigt (der Link bleibt unverbraucht
  und läuft ab). Eine unveränderte unzulässige Bestandsadresse blockiert das Speichern dagegen
  nicht mehr, sondern hinterlässt nur einen Log-Eintrag mit der Mitglieds-ID. Der Vergleich „schon
  synchron" prüft exakt gegen die kanonische Form statt `strcasecmp`, sonst galt
  „Anna@example.com" fälschlich als synchron.
- `member-email:sync-usernames --force` bricht bei ausgeschaltetem Schalter mit Fehlercode ab; der
  Probelauf bleibt immer erlaubt.
- **Bestätigung und Widerruf laufen jetzt nach demselben Sperrprotokoll:** Transaktion,
  Zeilensperre auf das Mitglied, danach Mitglied, Link-Datensatz und Anker frisch und sperrend
  nachlesen, dann Link-Verbrauch, Adresse, Benutzername, Anker und Token-Aufräumen gemeinsam
  festschreiben. Vorher hatte die Bestätigung weder Transaktion noch Sperre, sodass ein
  erfolgreicher Widerruf nachträglich überschrieben werden konnte. Der Widerruf sperrt nun über die
  Mitglieds-ID statt über die nicht indizierte Anker-Spalte.
- Schlägt der Versand des Widerrufslinks fehl, geht er nicht mehr verloren: Die neue Spalte
  `tl_member.emailChangeAnchorNotified` wird erst nach erfolgreichem Versand gesetzt, und ein
  stündlicher Cron stellt für gültige, unbenachrichtigte Anker einen neuen Link mit gleicher Frist
  aus.
- Technische Fehler im Widerruf enden in derselben allgemeinen Antwort statt in einer Fehlerseite;
  zurückgerollt wird nur bei aktiver Transaktion, und ein Fehler nach dem Commit stellt den Erfolg
  nicht mehr als Fehlschlag dar.
- Die Bestätigungsseite sendet `Cache-Control: private, no-store` und `X-Robots-Tag: noindex`; der
  Erfolgstext des Widerrufs nennt jetzt den Weg zu einem neuen Kennwort.

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
