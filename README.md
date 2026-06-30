# contao-confirm-member-email-change

Contao-Bundle (geplant): bestätigt die **Änderung der E-Mail-Adresse eines
Frontend-Mitglieds** (`tl_member`) im Profil per **Double-Opt-In-Link** — die neue
Adresse wird erst nach Klick auf den Bestätigungslink wirksam. Schließt eine vom
Contao-Kernteam selbst anerkannte Lücke ([contao/contao#258](https://github.com/contao/contao/issues/258)).

- **Scope:** nur Frontend-Members, nur der Änderungs-Fall (Registrierung ist im Core
  bereits bestätigt).
- **Prinzip:** maximal Contao-/Symfony-Core nutzen (OptIn-Service), minimal Custom-Code.
- **Kompatibilität:** Contao `^5.3` (läuft auf 5.3 und 5.7), PHP `^8.1`.

> **Status: Planungsphase, noch kein Code.**
> Vollständiger Kontext, verifizierte Fakten, Entscheidungen und die offenen
> Brainstorming-Fragen stehen in **[CONTEXT.md](CONTEXT.md)** (Kaltstart-Dokument).
