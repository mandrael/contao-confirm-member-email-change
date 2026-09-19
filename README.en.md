[🇩🇪 Deutsch](README.md) | **🇬🇧 English**

# Contao: Confirm member email change (double opt-in)

Confirms a **frontend member's email-address change** (`tl_member`) in the personal-data
module via a **double-opt-in link**: the new address only takes effect after the member clicks
a confirmation link. Closes a gap acknowledged by the Contao core team itself
([contao/contao#258](https://github.com/contao/contao/issues/258)).

> The first **5.3-only** bundle: modern Contao/Symfony features throughout
> (`AbstractBundle`, attribute DI), no 4.13 baggage.

## In a nutshell

- **Problem:** Contao stores an email changed in the profile **immediately, without
  confirmation.** With email-as-login (see below) that is a silent identity takeover or a
  self-lockout via typo.
- **Solution:** the change is intercepted, a confirmation link is sent to the **new** address,
  the old address is notified; `tl_member.email` only changes once confirmed.
- **Scope:** frontend members only, the **change** case only (registration is already confirmed
  by account activation in the core). Back end / import are left untouched.
- **Principle:** reuse the Contao/Symfony core as much as possible (the core `OptIn` service),
  minimal custom code.

## How it works

1. The member changes their email in the profile.
2. A high-priority `fields.email.save` callback intercepts the change, creates a core `OptIn`
   token and sends a confirmation link to the **new** address. The old address gets a security
   notice. The profile keeps showing the **old** address (no lockout, login still works) – with a
   prominent green notice that the change still needs to be confirmed.
3. The member opens the link → a thin controller confirms the token, writes the new address and
   – if an email-as-username extension is active – keeps the username in sync. A short confirmation
   page follows; with an email login active the member is logged out and signs back in with the
   new address.

## Installation

```bash
composer require mandrael/contao-confirm-member-email-change
```

The bundle registers itself via the Contao Manager Plugin – **no further configuration
needed**. After clicking the confirmation link the member sees a short confirmation page.
If an email login is active (see below), they are logged out in the process and then sign in
with the new address.

> **Technical note:** The emails are sent via the Symfony Mailer (`Contao\Email`). If the
> mailer is wired to Messenger – as in Contao's default setup – they go through the queue
> asynchronously, so a worker must be running (`contao:worker` or `messenger:consume`),
> otherwise the emails stay queued. With a synchronous mailer transport this does not apply.

## Email as username (opt-in, since 1.1)

An alternative to a separate extension: under `Settings → Email as username` you can switch on
**"Email as username"** directly in this bundle (default: off). Once enabled, exactly this rule
governs the login name:

- **Canonical rule:** login name = the lowercased, trimmed email address – only allowed at up to
  64 characters, passing Contao's own `extnd` character check (which excludes, among others,
  `# < > ( ) \ =`), and only if no other member already carries that name. If the address does not
  qualify, **saving the email is rejected** ("This address cannot be used as a login name").
- **Follow rule:** the username **always** follows the current email address – on registration, in
  the self-service profile, on a back end edit, and after a confirmed email change. An already
  different username is corrected the next time the member is saved; the one exception is a still
  **unconfirmed** email change - there the username stays at the old, still-valid address until it
  is confirmed.
- **The username field itself is no longer editable:** neither in the back end nor in a front end
  module (self-service profile, registration) - even if a module still has "username" configured
  as an editable field from before the switch was turned on.
- **Login form:** shows "Email address" instead of "Username" as the label, so members know what
  to log in with (label only, the form field is technically still named `username`).
- **Login with a different case:** if no member carries the exact typed username, the login also
  tries the lowercased variant (public site only, only when the input contains an "@") – an
  existing, differently-cased username is never shadowed by this.
- **Existing members with a different username** are corrected automatically the next time they
  are saved; to fix the whole existing database in one go, there is the console command:

  ```bash
  # Dry run (default) – prints only the ID and case class per member, no emails/usernames
  vendor/bin/contao-console member-email:sync-usernames

  # Actually writes the changes (back up the database first)
  vendor/bin/contao-console member-email:sync-usernames --force

  # Limit to one member group
  vendor/bin/contao-console member-email:sync-usernames --group=3 --force
  ```

### Compatibility with other email-as-username extensions

Either the built-in opt-in above **or** one of the following extensions – not both at once
(double sync on the same field):

| Extension | Behaviour with this bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) | Pure sync `username = email`. On confirmation the username is carried over **verbatim** (otherwise login with the new address would break). Only relevant while the built-in opt-in above is **off**. |
| heimrichhannot/contao-email2username-bundle | **Not Contao 5-ready:** version 1.4.0 relies on the "importUser" hook, which Contao 5 removed, so it is no longer supported here. |

With the built-in opt-in off and neither extension active, `tl_member.username` is left
untouched – unchanged from 1.0.

## Safety anchor (since 1.1)

An email change does not require a password – whoever hijacks an open profile session could
otherwise redirect the account's recovery channel to themselves, with the old address getting
nothing more than a consequence-free notice. So a **confirmed** change now also creates a
**safety anchor**: the old address gets a second mail with a link that can undo the change for
14 days.

- **Anchor, not the core `OptIn`:** dedicated, SQL-only columns on `tl_member`
  (`emailChangeAnchorHash`, `emailChangeAnchorEmail`, `emailChangeAnchorExpires`) instead of the
  core opt-in mechanism, whose validity window is hard-coded differently per Contao version.
  Only the SHA-256 hash of the token is stored; the plaintext only ever exists in the mail.
- **Chain rule:** if a still-valid anchor already exists at the next confirmed change, it stays
  untouched – otherwise an attacker who just took over the account could overwrite the real
  anchor with a second change and erase the original owner's own way back in. The oldest valid
  anchor wins and points back to the address BEFORE the chain. The old address of an
  in-between change gets the same notice, but **without** a link – it might belong to the
  attacker.
- **Revocation:** a `GET` on the link shows only a confirmation page (so a mail scanner
  pre-fetching links triggers nothing); only a `POST` with Contao's usual form token actually
  performs the change – under a row lock (`SELECT ... FOR UPDATE`) and a transaction, re-checking
  the hash, expiry, and whether the address to be restored meanwhile belongs to someone else. On
  success: the old address is restored, the username is carried back via the same follow rule as
  above, the password is invalidated (the `login` field is never touched – that stays the
  operator's call), every unconfirmed opt-in token of the member is deleted, and a currently
  logged-in session of that same member is logged out. Every failure shows the exact same
  generic message, regardless of the reason.
- **Cleanup:** a daily cron clears expired anchor fields so old addresses don't linger in
  `tl_member` indefinitely.

## Compatibility

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## License

MIT
