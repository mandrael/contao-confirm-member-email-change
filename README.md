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
2. Ein `fields.email.save`-Callback (hohe Priorität) fängt die Änderung ab; nach dem
   Speichern (`config.onsubmit`) entsteht ein Core-`OptIn`-Token und ein Bestätigungslink geht an
   die **neue** Adresse. Die alte
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
Konfiguration nötig**. Nach Installation und nach jedem Update `contao:migrate` ausführen (das Paket
legt Spalten in `tl_member` an). Nach dem Klick auf den Bestätigungslink sieht das Mitglied eine kurze
Bestätigungsseite. Ist ein E-Mail-Login aktiv (siehe unten), wird es dabei abgemeldet und
meldet sich anschließend mit der neuen Adresse an.

> **Technischer Hinweis:** Die Mails werden über den Symfony Mailer (`Contao\Email`) versendet.
> Ist der Mailer – wie im Contao-Standard-Setup – an den Messenger angebunden, laufen sie
> asynchron über die Queue; dann muss ein Worker laufen (`contao:worker` bzw.
> `messenger:consume`), sonst bleiben die Mails liegen. Bei synchronem Mailer-Transport
> entfällt das.

> **Voraussetzung:** Eine wirksame Administrator-E-Mail-Adresse muss gesetzt sein – entweder auf
> der Root-Seite oder in den globalen Einstellungen. Ohne sie schlägt jeder Versand (Bestätigung,
> Sicherheits-Benachrichtigung, Widerruf-Link) fehl; das Formular meldet dem Besucher trotzdem
> denselben Erfolg (ein Versandfehler wird nicht nach außen gespiegelt) – ein Fehlschlag ist ausschließlich im Log sichtbar.

## E-Mail als Benutzername (Opt-in, ab 1.1)

Alternative zu einer separaten Erweiterung: In `Einstellungen → E-Mail als Benutzername` lässt
sich **„E-Mail als Benutzername“** direkt in diesem Bundle einschalten (Standard: aus). Eingeschaltet,
gilt für den Login-Namen ausschließlich diese Regel:

- **Kanonische Regel:** Login-Name = kleingeschriebene, getrimmte E-Mail-Adresse – zulässig nur bei
  höchstens 64 Zeichen, Contaos eigener `extnd`-Zeichenprüfung (das schließt u. a. `# < > ( ) \ =` aus)
  und wenn kein anderes Mitglied diesen Namen bereits trägt. Passt die Adresse nicht, wird das
  **Speichern der E-Mail abgelehnt** („Diese Adresse kann nicht als Login-Name verwendet werden“).
- **Immer gleich der Adresse:** Der Benutzername folgt **immer** der aktuellen E-Mail-Adresse – bei der
  Registrierung (auch ein bereits vorbelegter Name wird dabei überschrieben), im
  Self-Service-Profil, bei Backend-Bearbeitung und nach einer bestätigten E-Mail-Änderung. Ein
  bereits abweichender Benutzername wird beim nächsten Speichern des Mitglieds korrigiert;
  einzige Ausnahme ist eine noch **unbestätigte** E-Mail-Änderung – dort bleibt der Benutzername
  bis zur Bestätigung an der alten, noch gültigen Adresse. Jeder Schreibzugriff ist an genau diese
  gelesene Adresse gebunden: Hat sie sich durch eine parallel abgeschlossene Bestätigung oder
  einen Widerruf bereits geändert, unterbleibt der Schreibzugriff und das nächste Speichern
  (oder `member-email:sync-usernames`) holt ihn nach.
- **Abgelehnt wird, bevor etwas entsteht:** Eine unzulässige Adresse wird schon beim Speichern
  des Formulars abgewiesen – in der Registrierung (das Mitglied wird gar nicht erst angelegt),
  im Self-Service-Profil (die Änderung wird nicht einmal angefordert) und im Backend. Wird sie
  erst zwischen Anforderung und Bestätigung unzulässig, etwa weil ein anderes Mitglied den
  Namen inzwischen belegt, wird die Änderung **nicht** bestätigt; der Link bleibt unverbraucht
  und läuft nach 24 Stunden von selbst ab.
- **Bestandsschutz beim Bearbeiten:** Eine bereits gespeicherte unzulässige Adresse blockiert das
  Speichern des Mitglieds **nicht**. Sie wird nur abgewiesen, wenn sie geändert wird; andernfalls
  bleibt der bisherige Benutzername stehen und es gibt einen Log-Eintrag mit der Mitglieds-ID.
- **Grenze der Automatik:** Der Abgleich hängt an Contaos DCA-Callbacks. Ein fremdes
  `Model::save()`, ein direkter SQL-Schreibzugriff oder ein Import lösen diese Callbacks nicht aus
  und können den Benutzernamen daher auseinanderlaufen lassen. Dafür gibt es den Konsolenbefehl
  `member-email:sync-usernames` (siehe unten), der genau das repariert.
- **Das Benutzername-Feld selbst ist nicht mehr editierbar:** weder im Backend noch in einem
  Frontend-Modul (Self-Service-Profil, Registrierung) – auch dann nicht, wenn ein Modul
  „username“ noch aus der Zeit vor dem Einschalten als editierbares Feld konfiguriert hat.
- **Login-Formular:** Zeigt statt „Benutzername“ die Beschriftung „E-Mail-Adresse“, damit
  Mitglieder wissen, womit sie sich anmelden (nur die Beschriftung, das Formularfeld heißt
  technisch weiterhin `username`).
- **Login mit abweichender Schreibweise:** Existiert kein Mitglied mit dem exakt eingegebenen
  Benutzernamen, wird beim Anmelden zusätzlich die kleingeschriebene Variante gesucht (nur an der
  öffentlichen Website, nur wenn die Eingabe ein „@“ enthält) – ein bestehender, anders
  geschriebener Benutzername wird dabei nie verdeckt. Diese Normalisierung gilt **unabhängig vom
  Schalter**: Sobald irgendein Benutzername wie eine E-Mail-Adresse aussieht (dieser Opt-in, eine
  der Erweiterungen unten oder ein von Hand vergebener Name), soll die Anmeldung bei ihm nicht an
  der Groß-/Kleinschreibung scheitern.
- **Bestandsmitglieder mit abweichendem Benutzernamen** korrigiert der automatische Abgleich beim
  nächsten Speichern von selbst; für den gesamten Bestand auf einmal gibt es den Konsolenbefehl:

  ```bash
  # Probelauf (Standard) – zeigt je Mitglied nur ID und Fallklasse, keine E-Mail-Adressen/Namen
  vendor/bin/contao-console member-email:sync-usernames

  # Schreibt tatsächlich (vorher ein Datenbank-Backup anlegen).
  # Nur bei eingeschaltetem Schalter – sonst bricht der Befehl mit Fehlercode ab,
  # damit ein ausgeschalteter Opt-in nicht doch den ganzen Bestand umstellt.
  vendor/bin/contao-console member-email:sync-usernames --force

  # Auf eine Mitgliedergruppe begrenzen
  vendor/bin/contao-console member-email:sync-usernames --group=3 --force
  ```

### Kompatibilität mit anderen E-Mail-als-Username-Erweiterungen

Aktiv **oder** eine der folgenden Erweiterungen – nicht beides gleichzeitig (Doppel-Sync auf
dasselbe Feld):

| Erweiterung | Verhalten mit diesem Bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) | Reiner Sync `username = email`. Beim Bestätigen wird der Benutzername **verbatim** mitgezogen (sonst bräche der Login mit der neuen Adresse). Ist das Paket installiert, bleibt der eigene Schalter **ohne Wirkung** – er wird zur Laufzeit als „aus“ behandelt, und das Einstellungsfeld sagt das auch. Bewusst kein `conflict` in der `composer.json`: der würde bestehende Installationen des veröffentlichten Pakets vom Update aussperren. |
| heimrichhannot/contao-email2username-bundle | **Nicht Contao-5-tauglich:** Version 1.4.0 nutzt den in Contao 5 entfernten `importUser`-Hook und wird daher nicht mehr unterstützt. |

Ist der eigene Opt-in ausgeschaltet und keine der Erweiterungen aktiv, bleibt
`tl_member.username` unangetastet – unverändert gegenüber 1.0.

**Bekannte Grenze:** Bei **eingeschaltetem** Schalter erzwingt der `UNIQUE`-Index auf
`tl_member.username` zusätzlich, dass zwei Mitglieder nie dieselbe E-Mail-Adresse tragen. Bei
**ausgeschaltetem** Schalter (und ohne aktive Erweiterung) besteht diese Absicherung nicht: Zwei
gleichzeitige Bestätigungen derselben, bis dahin freien Zieladresse sind nicht datenbankseitig
ausgeschlossen – `tl_member.email` ist DCA-eindeutig, aber nicht per Datenbank-Constraint.

## Sicherheitsanker (ab 1.1)

Eine E-Mail-Änderung verlangt kein Kennwort – wer eine offene Profilsitzung kapert, könnte
den Wiederherstellungskanal des Kontos sonst auf sich umstellen, ohne dass die alte Adresse
mehr als eine folgenlose Benachrichtigung bekommt. Deshalb legt eine **bestätigte** Änderung
zusätzlich einen **Sicherheitsanker** an: Die alte Adresse erhält eine zweite Mail mit einem
Link, der die Änderung 14 Tage lang rückgängig machen kann.

- **Anker, nicht Core-`OptIn`:** eigene, nur per SQL angelegte Felder an `tl_member`
  (`emailChangeAnchorHash`, `emailChangeAnchorEmail`, `emailChangeAnchorExpires`,
  `emailChangeAnchorNotified`, `emailChangeAnchorPending`) statt des
  Core-Opt-in-Mechanismus, dessen Gültigkeit je Contao-Version unterschiedlich fest verdrahtet
  ist. Gespeichert wird der SHA-256-Hash des Tokens; der Klartext steht in der Mail und – nur für
  die Dauer eines noch ausstehenden Versands – zusätzlich in `emailChangeAnchorPending` (genauso
  handhabt es Contaos eigenes `tl_opt_in` mit Bestätigungslinks). Sobald die Mail draußen ist, wird
  das Feld sofort wieder geleert.
- **Ketten-Regel:** Existiert beim nächsten bestätigten Wechsel noch ein gültiger Anker, bleibt
  er unverändert – sonst könnte ein Angreifer, der das Konto gerade übernommen hat, den echten
  Anker mit einem zweiten Wechsel überschreiben und die Rückholmöglichkeit des ursprünglichen
  Besitzers löschen. Der älteste gültige Anker gewinnt und führt auf die Adresse VOR der Kette.
  Die alte Adresse eines zwischenzeitlichen Wechsels bekommt in diesem Fall dieselbe
  Benachrichtigung, aber **ohne** Link – sie könnte dem Angreifer gehören.
- **Widerruf:** Ein `GET` auf den Link zeigt nur eine Bestätigungsseite (ein Mail-Scanner, der
  Links vorab abruft, löst dadurch nichts aus); erst ein `POST` mit Contaos üblichem
  Formular-Token führt die Änderung durch – unter Zeilensperre (`SELECT … FOR UPDATE`) und
  Transaktion, mit erneuter Prüfung von Hash, Ablauf und ob die wiederherzustellende Adresse
  inzwischen einem anderen Konto gehört. Bei Erfolg: alte Adresse wiederhergestellt, Benutzername
  wie oben an die wiederhergestellte Adresse angeglichen, Kennwort ungültig gemacht (kein login-Feld
  wird angetastet – das bleibt Betreiber-Sache), alle unbestätigten Opt-in-Token des Mitglieds
  gelöscht, eine gerade angemeldete Sitzung dieses Mitglieds abgemeldet. Kann der Benutzername
  der wiederhergestellten Adresse nicht folgen, weil ihn inzwischen jemand anderes trägt, wird der
  Widerruf trotzdem durchgeführt – Adresse und Kennwort sind eine Sicherheitsfunktion und dürfen
  daran nicht scheitern; der Benutzername bleibt dann stehen und der Betreiber bekommt einen
  Log-Eintrag. Jeder Fehlschlag zeigt dieselbe allgemeine Meldung, unabhängig vom Grund – auch ein
  rein technischer.
- **Verlorene Benachrichtigung:** Schlägt der Versand fehl, bliebe die einzige Rückholmöglichkeit
  unbrauchbar. Deshalb wird `emailChangeAnchorNotified` erst gesetzt, wenn die Mail wirklich
  draußen ist; ein stündlicher Cron sendet für gültige, unbenachrichtigte Anker **denselben**
  bereits ausgestellten Link erneut (aus `emailChangeAnchorPending`) – die Frist wird nie
  verlängert und der Link nie rotiert, ein doppelter Versand desselben Links ist unschädlich.
  Läuft der Cron per CLI, muss `framework.router.default_uri` gesetzt sein, sonst kennt die
  Kommandozeile die Domain der Website nicht.
- **Sperrprotokoll:** Bestätigung und Widerruf laufen nach demselben Ablauf – Transaktion,
  Zeilensperre auf das Mitglied (`SELECT … FOR UPDATE`), danach alles frisch und sperrend
  nachlesen, dann Link-Verbrauch, Adresse, Benutzername, Anker und das Aufräumen offener Token
  gemeinsam festschreiben. Bewusst **ohne** benannten `GET_LOCK`: ein Verzeichnis-Bundle, das vor
  dieser Zeilensperre einen benannten Lock nimmt, bekäme sonst die umgekehrte Sperrreihenfolge.
- **Aufräumen:** Ein täglicher Cron leert abgelaufene Anker-Felder, damit alte Adressen nicht
  unbegrenzt in `tl_member` liegen bleiben.

## Kompatibilität

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## Lizenz

MIT
