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
- **Follow rule:** the username follows **automatically only** if it is empty or (case-insensitively)
  matches the previous email address – on registration, in the self-service profile, and after a
  confirmed email change. An already **different ("fantasy") username is NEVER overwritten
  automatically.**
- **Login with a different case:** if no member carries the exact typed username, the login also
  tries the lowercased variant (public site only, only when the input contains an "@") – an
  existing, differently-cased username is never shadowed by this.
- **Existing members with a different username** are only migrated via the console command:

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

## Compatibility

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## License

MIT
