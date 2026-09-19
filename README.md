**🇩🇪 Deutsch** | [🇬🇧 English](README.en.md)

# Contao: E-Mail-Änderung bestätigen (Double-Opt-In)

Bestätigt die **Änderung der E-Mail-Adresse eines Frontend-Mitglieds** (`tl_member`) im
Profil per **Double-Opt-In-Link**: Die neue Adresse wird erst nach Klick auf einen
Bestätigungslink wirksam. Schließt eine vom Contao-Kernteam selbst anerkannte Lücke
([contao/contao#258](https://github.com/contao/contao/issues/258)).

> Erstes **5.3-only**-Bundle: durchgängig moderne Contao-/Symfony-Features
> (`AbstractBundle`, Attribut-DI), kein 4.13-Ballast.

## Das Wichtigste in Kürze

- **Problem:** Contao speichert eine im Profil geänderte E-Mail **direkt, ohne Bestätigung**.
  Bei E-Mail-als-Login (siehe unten) ist das eine stille Identitäts-Übernahme bzw. ein
  Self-Lockout per Tippfehler.
- **Lösung:** Die Änderung wird abgefangen, ein Bestätigungslink an die **neue** Adresse
  gesendet, die alte Adresse benachrichtigt; `tl_member.email` ändert sich erst nach Bestätigung.
- **Scope:** nur Frontend-Members, nur der **Änderungs-Fall** (die Registrierung ist im Core
  bereits per Account-Aktivierung bestätigt). Backend/Import bleiben unangetastet.
- **Prinzip:** maximal Contao-/Symfony-Core nutzen (Core-`OptIn`-Service), minimal Custom-Code.

## So funktioniert es

1. Mitglied ändert im Profil seine E-Mail-Adresse.
2. Ein `fields.email.save`-Callback (hohe Priorität) fängt die Änderung ab, erstellt einen
   Core-`OptIn`-Token und sendet einen Bestätigungslink an die **neue** Adresse. Die alte
   Adresse erhält eine Sicherheits-Benachrichtigung. Im Profil bleibt die **alte** Adresse
   sichtbar (kein Lockout, Login unverändert möglich) – mit einem deutlichen grünen Hinweis,
   dass die Änderung noch bestätigt werden muss.
3. Mitglied öffnet den Link → ein schlanker Controller bestätigt den Token, schreibt die neue
   Adresse und – falls eine E-Mail-als-Username-Erweiterung aktiv ist – zieht den Benutzernamen
   mit. Es folgt eine kurze Bestätigungsseite; bei aktivem E-Mail-Login wird das Mitglied
   abgemeldet und meldet sich mit der neuen Adresse neu an.

## Installation

```bash
composer require mandrael/contao-confirm-member-email-change
```

Das Bundle registriert sich über den Contao Manager Plugin automatisch – **keine weitere
Konfiguration nötig**. Nach dem Klick auf den Bestätigungslink sieht das Mitglied eine kurze
Bestätigungsseite. Ist ein E-Mail-Login aktiv (siehe unten), wird es dabei abgemeldet und
meldet sich anschließend mit der neuen Adresse an.

> **Technischer Hinweis:** Die Mails werden über den Symfony Mailer (`Contao\Email`) versendet.
> Ist der Mailer – wie im Contao-Standard-Setup – an den Messenger angebunden, laufen sie
> asynchron über die Queue; dann muss ein Worker laufen (`contao:worker` bzw.
> `messenger:consume`), sonst bleiben die Mails liegen. Bei synchronem Mailer-Transport
> entfällt das.

## E-Mail als Benutzername (Opt-in, ab 1.1)

Alternative zu einer separaten Erweiterung: In `Einstellungen → E-Mail als Benutzername` lässt
sich **„E-Mail als Benutzername"** direkt in diesem Bundle einschalten (Standard: aus). Eingeschaltet,
gilt für den Login-Namen ausschließlich diese Regel:

- **Kanonische Regel:** Login-Name = kleingeschriebene, getrimmte E-Mail-Adresse – zulässig nur bei
  höchstens 64 Zeichen, Contaos eigener `extnd`-Zeichenprüfung (das schließt u. a. `# < > ( ) \ =` aus)
  und wenn kein anderes Mitglied diesen Namen bereits trägt. Passt die Adresse nicht, wird das
  **Speichern der E-Mail abgelehnt** („Diese Adresse kann nicht als Login-Name verwendet werden").
- **Folgeregel:** Der Benutzername folgt **automatisch nur**, wenn er leer ist oder (ohne
  Berücksichtigung von Groß-/Kleinschreibung) der bisherigen E-Mail-Adresse entspricht – bei der
  Registrierung, im Self-Service-Profil und nach einer bestätigten E-Mail-Änderung. Ein bereits
  **abweichender („Fantasie"-)Benutzername wird NIE automatisch überschrieben.**
- **Login mit abweichender Schreibweise:** Existiert kein Mitglied mit dem exakt eingegebenen
  Benutzernamen, wird beim Anmelden zusätzlich die kleingeschriebene Variante gesucht (nur an der
  öffentlichen Website, nur wenn die Eingabe ein „@" enthält) – ein bestehender, anders
  geschriebener Benutzername wird dabei nie verdeckt.
- **Bestandsmitglieder mit abweichendem Benutzernamen** werden ausschließlich über den
  Konsolenbefehl umgestellt:

  ```bash
  # Probelauf (Standard) – zeigt je Mitglied nur ID und Fallklasse, keine E-Mail-Adressen/Namen
  vendor/bin/contao-console member-email:sync-usernames

  # Schreibt tatsächlich (vorher ein Datenbank-Backup anlegen)
  vendor/bin/contao-console member-email:sync-usernames --force

  # Auf eine Mitgliedergruppe begrenzen
  vendor/bin/contao-console member-email:sync-usernames --group=3 --force
  ```

### Kompatibilität mit anderen E-Mail-als-Username-Erweiterungen

Aktiv **oder** eine der folgenden Erweiterungen – nicht beides gleichzeitig (Doppel-Sync auf
dasselbe Feld):

| Erweiterung | Verhalten mit diesem Bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) | Reiner Sync `username = email`. Beim Bestätigen wird der Benutzername **verbatim** mitgezogen (sonst bräche der Login mit der neuen Adresse). Nur relevant, solange der eigene Opt-in oben **ausgeschaltet** ist. |
| heimrichhannot/contao-email2username-bundle | **Nicht Contao-5-tauglich:** Version 1.4.0 nutzt den in Contao 5 entfernten `importUser`-Hook und wird daher nicht mehr unterstützt. |

Ist der eigene Opt-in ausgeschaltet und keine der Erweiterungen aktiv, bleibt
`tl_member.username` unangetastet – unverändert gegenüber 1.0.

## Kompatibilität

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## Lizenz

MIT
