# contao-confirm-member-email-change — Kontext & Handover

> **Kontext-/Handover-Dokument.** Recherche, Entscheidungen und Rationale (§1–§9) gelten weiter.
> **Status: implementiert, auf cont5 (Contao 5.3.47) end-to-end verifiziert, nach GitHub gepusht**
> (`dev-main`). Offen vor dem Tag: Code-Review + optionaler 5.7-Runtime-Smoke → dann `v1.0.0` + Packagist.
> Aktuelle nutzerseitige Doku: `README.md` / `README.en.md` / `CHANGELOG.md`. Stand: 2026-07-05.
> (Historie: bis 2026-06-30 reine Planungsphase; siehe §10.)

## 1. Zweck & Scope

Ein Contao-Bundle, das die **Änderung der E-Mail-Adresse eines Frontend-Mitglieds**
(`tl_member`) im Profil per **Double-Opt-In-Link** bestätigt: neue Adresse wird erst
nach Klick auf einen Bestätigungslink wirksam.

- **Nur Members** (`tl_member`), **nicht** Backend-User (`tl_user`).
- **Nur der Änderungs-Fall** im FE-Self-Service (`ModulePersonalData`).
  Die **Registrierung** ist im Core bereits per Account-Aktivierung bestätigt → außerhalb.
- **Leitprinzip:** maximal Contao-/Symfony-Core-Features nutzen, minimal Custom-Code.
  Nichts nachbauen, was Core/Symfony schon liefert (kein eigenes Token-/Mail-System).

## 2. Warum das Bundle (Problem)

Im installierten **Contao 5.3.47** speichert das Profilmodul eine geänderte E-Mail
**direkt, ohne Bestätigung** (verifiziert, `ModulePersonalData.php` ~Z. 304–353).
Das ist ein Sicherheitsthema:
- Bei **E-Mail = Username** (geplanter Einsatz von `terminal42/contao-mailusername`)
  ist eine unbestätigte Änderung eine **stille Login-Identitäts-Übernahme** bzw. ein
  **Self-Lockout per Tippfehler**.
- Auch **ohne** E-Mail-Username: Passwort-Reset, Buchungs-/Bestätigungsmails und die
  Integrität der Mitgliederliste hängen an einer **verifizierten** Adresse.

## 3. Recherche-Ergebnis (belegt)

- **Kein gepflegtes Plugin** löst das für Contao 4/5 (Packagist-Such-API + Kandidaten
  geprüft). `heimrichhannot/contao-confirmed_email` = nur „E-Mail zweimal eintippen"
  (Tippfehler-Schutz), **Contao 3, tot seit 2016** — irrelevant.
- **Bekannte Core-Lücke:** Issue **contao/contao#258 „Confirm e-mail address changes"**,
  2016 von **leofeyer** (Contao-Gründer) gestellt, *closed/completed* — aber **nur der
  OptIn-Service** (#196) wurde gebaut, **der Konsument im Profilmodul nie fertiggestellt**.
  leofeyers Plan war: generischer OptIn-Service für beliebige Tabellen/Felder → genutzt
  von personalData + newsletter. Genau diesen fehlenden Konsumenten bauen wir.
- Entscheidung: **eigenes, member-spezifisches Bundle** — kein PR an terminal42
  (dessen Scope = „E-Mail *ist* Username", orthogonal), nicht generisch/table-agnostisch
  (YAGNI). Später Upstream in den Core wäre denkbar (größerer Brocken: + Newsletter, BC, Tests).

## 4. Verifizierte technische Fakten (mit Fundstellen, Prod 5.3.47)

Pfad: `/home/nkinstitute/web/nkinstitute.at/public_html/vendor/contao/core-bundle/`

- **Abfangpunkt vorhanden:** Das FE-Profilmodul feuert **beide** relevanten Callbacks:
  - `contao/modules/ModulePersonalData.php:259` → Field-`save_callback` (`fields.email.save`)
  - `contao/modules/ModulePersonalData.php:377` → `config.onsubmit_callback`
  - speichert E-Mail sonst direkt (~Z. 304–353), **keine** Bestätigung.
- **Registrierung:** `ModuleRegistration.php:252` (Field-`save_callback`) + `createNewUser`-Hook
  (`:329/:355/:412`). (Schon per Aktivierung bestätigt → wir fassen das nicht an.)
- **Core-OptIn-Service** (`src/OptIn/OptIn.php`) — das Werkzeug, **API in 4.13/5.3/5.7 identisch**:
  - `OptIn::create(string $prefix, string $email, array $related): OptInTokenInterface`
  - `OptIn::find(string $identifier): ?OptInTokenInterface`
  - Token: `->send(?subject, ?text)`, `->confirm()`, `->isValid()`, `->isConfirmed()`,
    `->getEmail()`, `->getRelatedRecords()`
  - Exceptions: `OptInTokenNoLongerValidException`, `OptInTokenAlreadyConfirmedException`
  - Token-TTL Default 24 h. `tl_opt_in`-Tabelle.
- **Login-Identifier** (Kontext): `User::loadUserByIdentifier()` sucht hart über
  `findBy('username', …)` (`contao/library/Contao/User.php:332`). Kein E-Mail-Login im Core.
  `Member` überschreibt das nicht. `tl_member.username` = unique, `email` nur index.

## 5. Zusammenspiel mit terminal42/contao-mailusername (2.2.0)

(Für E-Mail-Login gewählt — Sync-Ansatz: schreibt E-Mail in `username`.)
- Synct via Hook `createNewUser` + DCA-Callback `fields.email.save` (mit `LOCK TABLES`),
  setzt `email.eval.unique=true`, `username` rgxp=email/disabled/nullable, Collation
  `utf8mb4_unicode_ci` (Login case-insensitiv), relabelt Label username→E-Mail.
- **Folge für uns:** Unser `fields.email.save`-Callback gibt den **ALTEN** Wert zurück
  (E-Mail ändert sich bis Bestätigung nicht) → terminal42 synct keine neue Mail vorzeitig,
  Login bleibt mit alter Adresse intakt (kein Lockout während „pending").
- **Aber:** Beim Bestätigungs-Schreiben schreiben wir **programmatisch** → DCA-`save_callback`
  feuern dann **nicht** → terminal42 synct den `username` **nicht** automatisch. → siehe §6 B4.

## 6. Brainstorming-Entscheidungen — ENTSCHIEDEN 2026-06-30

User-Vorgabe: „max. Core (Contao/Symfony), minimal Custom-Code, nur was nicht schon
abgedeckt ist" → daher durchweg die core-lastige Variante gewählt.

- **B1 — Bestätigungslink:** ✅ **Symfony-Route + schlanker Controller** (kein Admin-Seiten-Setup,
  wenigster Code); Redirect auf konfigurierbare jumpTo-Seite für die Erfolg/Fehler-Meldung.
  (Kein generischer Opt-In-Confirm-Route im Core vorhanden → minimaler eigener Handler nötig.)
- **B2 — Mailversand:** ✅ **Core `OptInToken->send()`** (0 Dependencies, im OptIn-Service eingebaut).
  notification_center bewusst NICHT (vermeidet +1 Dependency).
- **B3 — Unbestätigte neue E-Mail:** ✅ **Nur im OptIn-Token** (`getEmail()`); `tl_member.email`
  bleibt bis Bestätigung unverändert. **Kein DCA-Feld.**
- **B4 — terminal42-username-Sync beim Bestätigungs-Write:** ✅ **Soft-Dependency** (wenn aktiv,
  `username` mitziehen) + Callback-Prioritäten so, dass terminal42 die neue Mail nicht vorzeitig
  synct. ⚠️ **Genaue Mechanik/Prioritäten im Plan verifizieren** (einziger offener Technik-Punkt).
- **B5 — Alte Adresse benachrichtigen:** ✅ **Ja, default an** (Sicherheits-Standard).
- **B6 — Backend-Admin-Änderungen:** ✅ **Außerhalb des Scope** — nur FE-Self-Service. Optional später.
- **B7 — Token-TTL/alter Token:** ✅ **Core-Default 24 h**; alten unbestätigten Token bei neuer
  Anforderung ersetzen. Minimal-Config.

Damit sind alle Produktfragen geklärt. Einziger im Plan zu verifizierender Technik-Punkt: **B4**.

## 7. Edge-Cases-Checkliste (im Plan abdecken)

1. Eindeutigkeit der **neuen** E-Mail prüfen (kein Token auf fremde/schon-vergebene Adresse).
2. Token-Ablauf (24 h) + **Pending-UX** (Profil zeigt alte Mail + „Bestätigung an NEU@… gesendet").
3. Alte Adresse benachrichtigen (B5).
4. terminal42-`username`-Sync beim Bestätigungs-Write (B4).
5. Nur **Änderungs**-Fall; Registrierung/Backend/Import unangetastet (B6).
6. Login während „pending" bleibt mit alter Adresse möglich (kein Lockout) — sicherstellen.

## 8. Entscheidungen (locked)

| Thema | Entscheidung |
|---|---|
| Scope | nur Frontend-Members (`tl_member`), nur Änderungs-Fall |
| Mechanik | `fields.email.save`-Callback fängt ab → Core-OptIn-Token → Bestätigungs-Handler schreibt |
| E-Mail-Login (separat) | `terminal42/contao-mailusername` 2.2.0 (Sync-Ansatz) |
| Kompatibilität | **`contao/core-bundle: ^5.3`** (läuft auf 5.3 *und* 5.7) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` (5.3 = SF 6.4, 5.7 = SF 7.4) |
| **4.13** | **raus** (EOL seit 14.02.2026; SF-5.4-Altlast vermeiden) |
| Bundle-Skeleton | Symfony `AbstractBundle` (SF 6.1+), Attribut-DI, `#[AsHook]`/`#[AsCallback]` |
| Prinzip | max. Core/Symfony-Reuse, minimal Custom-Code |
| Vendor/Paketname | **offen** (z. B. `mandrael/…` wie csrf-fix?), Name = `contao-confirm-member-email-change` |

### Versions-Matrix (verifiziert aus core-bundle/composer.json)

| | Contao 4.13 | Contao 5.3 | Contao 5.7 |
|---|---|---|---|
| PHP | `^7.4 \|\| ^8.0` | `^8.1` | `^8.3` |
| Symfony | `^5.4` | `^6.4` | `^7.4` |
| Twig | `^3.8` | 3.x | `^3.21` |
| `#[AsHook]`/`#[AsCallback]` | ✅ | ✅ | ✅ |
| OptIn-Service | ✅ (gleiche API) | ✅ | ✅ |
| `AbstractBundle`/Attribut-DI | ❌ (SF 5.4) | ✅ | ✅ |
| Status (30.06.2026) | **EOL** | LTS-Tail | **aktuelle LTS** |

### Geplante composer-Constraints (Entwurf, noch keine Datei)

```json
"require": {
    "php": "^8.1",
    "contao/core-bundle": "^5.3",
    "symfony/config": "^6.4 || ^7.0",
    "symfony/dependency-injection": "^6.4 || ^7.0",
    "symfony/http-kernel": "^6.4 || ^7.0",
    "symfony/routing": "^6.4 || ^7.0"
}
```
Test-Matrix: Contao 5.3 + PHP 8.1 **und** Contao 5.7 + PHP 8.3. Gegen SF-6.4-API
deprecation-frei schreiben → läuft unverändert auf SF 7.4.

## 9. Quellen

- contao/contao#258 — Confirm e-mail address changes (Core-Lücke, leofeyer)
- contao/contao#196 — OptIn-Service (das gebaute Werkzeug)
- terminal42/contao-mailusername — github.com/terminal42/contao-mailusername
- Core-Code Prod 5.3.47 (ModulePersonalData/ModuleRegistration/OptIn/User) — Fundstellen §4/§5
- Versions-Constraints: core-bundle/composer.json @ Tags 4.13.58 / Branches 5.3 / 5.7

## 10. Stand & nächste Schritte (Update 2026-07-05)

**Erledigt.** Bundle implementiert (AbstractBundle + Attribut-DI, `#[AsCallback]`/`#[AsController]`/`#[AsHook]`).
Auf cont5 (Contao 5.3.47, PHP 8.3) end-to-end verifiziert — inkl. terminal42/heimrichhannot/ohne-Erweiterung,
grüner Pending-Box, self-contained Bestätigungsseite + `Security::logout()` bei Identitätswechsel und
E-Mail-spezifischer Unique-Meldung (FE-only). PHPUnit + PHPStan (auch gegen `core-bundle 5.7.7`) grün,
GitHub-Actions-CI eingerichtet. Nach GitHub gepusht (`dev-main`, Commit-Identität
`Michael Gasperl <michael@gasperl.at>`, kein Co-Author/keine Session-URL). cont5 zieht `dev-main` via VCS.
Die relevanten `ModulePersonalData`-Interna (savedData-Flash, Raw-Message-Template, Unique-Check) sind in
5.7.7 identisch zu 5.3 → 5.7-Runtime-Risiko gering.

**Offen vor dem Tag.**
1. Code-Review (Fable/Codex + optional eingebautes `/code-review`) → Findings einarbeiten.
2. Optional: 5.7-Runtime-Smoke-Test (ddev/colima).
3. Dann `v1.0.0` taggen + bei Packagist eintragen (Empfehlung 1.0.0; konservativ 0.1.0).

Historie (Planungsphase, bis 2026-06-30): Doku abgelegt, B1–B7 entschieden (§6), Implementierungsplan,
dann Code. Brainstorming-Entscheidungen stehen unverändert in §6/§8.
