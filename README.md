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
   sichtbar (kein Lockout, Login unverändert möglich) — mit einem deutlichen grünen Hinweis,
   dass die Änderung noch bestätigt werden muss.
3. Mitglied öffnet den Link → ein schlanker Controller bestätigt den Token, schreibt die neue
   Adresse und – falls eine E-Mail-als-Username-Erweiterung aktiv ist – zieht den Benutzernamen
   mit. Es folgt eine kurze Bestätigungsseite; bei aktivem E-Mail-Login wird das Mitglied
   abgemeldet und meldet sich mit der neuen Adresse neu an.

## Installation

```bash
composer require mandrael/contao-confirm-member-email-change
```

Das Bundle registriert sich über den Contao Manager Plugin automatisch — **keine weitere
Konfiguration nötig**. Nach dem Klick auf den Bestätigungslink sieht das Mitglied eine kurze
Bestätigungsseite. Ist ein E-Mail-Login aktiv (siehe unten), wird es dabei abgemeldet und
meldet sich anschließend mit der neuen Adresse an.

## Kompatibilität: E-Mail als Benutzername

Es gilt **entweder/oder** – beide Erweiterungen lösen dieselbe Aufgabe und werden **nicht
gemeinsam** installiert:

| Erweiterung | Verhalten mit diesem Bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) (empfohlen) | Reiner Sync `username = email`. Beim Bestätigen wird der Benutzername **verbatim** mitgezogen (sonst bräche der Login mit der neuen Adresse). |
| [heimrichhannot/contao-email2username-bundle](https://github.com/heimrichhannot/contao-email2username-bundle) | Sync **+** Login-Decorator. Login funktioniert ohnehin; der Benutzername wird kosmetisch (lowercase) mitgezogen. |

Ohne eine solche Erweiterung bleibt `tl_member.username` unangetastet.

## Kompatibilität

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## Lizenz

MIT
