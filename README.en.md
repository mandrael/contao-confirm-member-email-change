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
2. A high-priority `fields.email.save` callback intercepts the change; after the
   form is saved (`config.onsubmit`) a core `OptIn` token is created and a confirmation link is sent
   to the **new** address. The old address gets a security
   notice. The profile keeps showing the **old** address (no lockout, login still works) – with a
   prominent green notice that the change still needs to be confirmed.
3. The member opens the link → a thin controller confirms the token, writes the new address and
   – if the built-in switch or an email-as-username extension is active – keeps the username in sync. A short confirmation
   page follows; with an email login active the member is logged out and signs back in with the
   new address.

Changing the password via the "change password" or "lost password" modules discards a still open
confirmation link: these core modules delete every unconfirmed opt-in token of the member. The
change then has to be requested again in the profile.

## Installation

```bash
composer require mandrael/contao-confirm-member-email-change
```

The bundle registers itself via the Contao Manager Plugin – **no further configuration
needed**. Run `contao:migrate` after installing and after every update (the package adds columns to
`tl_member`). After clicking the confirmation link the member sees a short confirmation page.
If an email login is active (see below), they are logged out in the process and then sign in
with the new address.

> **Technical note:** The emails are sent via the Symfony Mailer (`Contao\Email`). If the
> mailer is wired to Messenger – as in Contao's default setup – they go through the queue
> asynchronously, so a worker must be running (`contao:worker` or `messenger:consume`),
> otherwise the emails stay queued. With a synchronous mailer transport this does not apply.

> **Requirement:** an effective administrator email address must be set – either on the root
> page or in the global settings (with several websites using different addresses it has to be the
> global one, because the confirmation page and the cron do not know the website). Without it every send (confirmation, security notice, revoke
> link) fails; the form still reports the same success to the visitor (a failed send is
> never mirrored to the visitor) – a failed send is visible only
> in the log.

## Email as username (opt-in, since 1.1)

An alternative to a separate extension: under `Settings → Members: email as username` you can switch on
**"Email address as member username"** directly in this bundle (default: off). Once enabled, exactly this rule
governs the login name:

- **Canonical rule:** login name = the lowercased, trimmed email address – only allowed at up to
  64 characters, passing Contao's own `extnd` character check (which excludes, among others,
  `# < > ( ) \ =`), and only if no other member already carries that name. If the address does not
  qualify, **saving the email is rejected** ("This address cannot be used as a login name").
- **Always equal to the address:** the username **always** follows the current email address – on registration
  (overwriting an already pre-filled name too), in the self-service profile, on a back end edit,
  and after a confirmed email change. An already different username is corrected the next time
  the member is saved; the one exception is a still **unconfirmed** email change - there the
  username stays at the old, still-valid address until it is confirmed. Every write is bound to
  exactly the address it just read: if that address already changed through a confirmation or
  revocation racing this very save, the write is skipped and the next save (or
  `member-email:sync-usernames`) catches up.
- **Rejected before anything comes into existence:** an ineligible address is turned down when the
  form is saved - during registration (no member is created at all), in the self-service profile
  (the change is not even requested) and in the back end. If it only becomes ineligible between
  the request and the confirmation, e.g. because another member took the name meanwhile, the
  change is **not** confirmed; the link stays unused and expires by itself after 24 hours.
- **Existing records stay editable:** an address that was ALREADY stored while being ineligible
  does **not** block saving the member. It is only rejected when it is changed; otherwise the
  previous username stays and a log entry with the member ID is written.
- **Limit of the automatic sync:** it hangs off Contao's DCA callbacks. A foreign `Model::save()`,
  a direct SQL write or an import does not fire those callbacks and can therefore let the username
  drift apart. That is what the `member-email:sync-usernames` console command below repairs.
- **The username field itself is no longer editable:** neither in the back end nor in a front end
  module (self-service profile, registration) - even if a module still has "username" configured
  as an editable field from before the switch was turned on.
- **Login form:** shows "Email address" instead of "Username" as the label, so members know what
  to log in with (label only, the form field is technically still named `username`).
- **Login with a different case:** if no member carries the exact typed username, the login looks
  for a member whose username differs only in case; if there is exactly one, its name is used,
  otherwise the lowercased variant (public site only, only when the input contains an "@"). An
  exactly matching username is never shadowed by this. This normalization applies
  **regardless of the switch**: as soon as any username looks like an email address (this opt-in,
  one of the extensions below, or a manually assigned name), login should not fail on case alone.
- **Existing members with a different username** are corrected automatically the next time they
  are saved; to fix the whole existing database in one go, there is the console command:

  ```bash
  # Dry run (default) – prints only the ID and case class per member, no emails/usernames
  vendor/bin/contao-console member-email:sync-usernames

  # Actually writes the changes (back up the database first).
  # Only with the switch turned on - otherwise the command aborts with an error code,
  # so a disabled opt-in can never rewrite the whole member base anyway.
  vendor/bin/contao-console member-email:sync-usernames --force

  # Limit to one member group
  vendor/bin/contao-console member-email:sync-usernames --group=3 --force
  ```

### Compatibility with other email-as-username extensions

Either the built-in opt-in above **or** one of the following extensions – not both at once
(double sync on the same field):

| Extension | Behaviour with this bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) | Pure sync `username = email`. On confirmation the username is carried over **verbatim** (otherwise login with the new address would break). While the package is installed, the built-in switch stays **without effect** - it is treated as "off" at runtime and the settings field says so. Deliberately no composer `conflict`: that would lock existing installs of the published package out of an update. |
| heimrichhannot/contao-email2username-bundle | **Not Contao 5-ready:** version 1.4.0 relies on the "importUser" hook, which Contao 5 removed, so it is no longer supported here. |

With the built-in opt-in off and neither extension active, `tl_member.username` is left
untouched – unchanged from 1.0.

**Known limitation:** with the switch **on**, the `UNIQUE` index on `tl_member.username` as a
rule also keeps two members from carrying the same email address – not for members without a
username (such as after two simultaneous registrations with the same address) and not after a
revocation that deliberately restores an address carried twice meanwhile (see below). With the switch
**off** (and no extension active), that guarantee does not exist: two simultaneous confirmations
of the same, previously free target address are not excluded at the database level -
`tl_member.email` is unique by DCA rule, not by a database constraint.

## Safety anchor (since 1.1)

An email change does not require a password – whoever hijacks an open profile session could
otherwise redirect the account's recovery channel to themselves, with the old address getting
nothing more than a consequence-free notice. So a **confirmed** change now also creates a
**safety anchor**: the old address gets a second mail with a link that can undo the change for
14 days.

- **Anchor, not the core `OptIn`:** dedicated, SQL-only columns on `tl_member`
  (`emailChangeAnchorHash`, `emailChangeAnchorEmail`, `emailChangeAnchorExpires`,
  `emailChangeAnchorNotified`, `emailChangeAnchorPending`) instead of the
  core opt-in mechanism, whose validity window is hard-coded differently per Contao version.
  The SHA-256 hash of the token is stored; the plaintext lives in the mail and – only for as
  long as a send is still pending – also in `emailChangeAnchorPending` (the core's own
  `tl_opt_in` stores confirmation links in plaintext the same way). It is cleared again the
  moment the mail goes out.
- **Chain rule:** if a still-valid anchor already exists at the next confirmed change, it stays
  untouched – otherwise an attacker who just took over the account could overwrite the real
  anchor with a second change and erase the original owner's own way back in. The oldest valid
  anchor wins and points back to the address BEFORE the chain. The old address of an
  in-between change gets the same notice, but **without** a link – it might belong to the
  attacker.
- **Revocation:** a `GET` on the link shows only a confirmation page (so a mail scanner
  pre-fetching links triggers nothing); only a `POST` with Contao's usual form token actually
  performs the change – under a row lock (`SELECT ... FOR UPDATE`) and a transaction, re-checking
  the hash and expiry. On success: the old address is restored, the username is aligned with the
  restored address as above (also when the switch has been turned off since, as long as the
  username still was the replaced address), the password is invalidated (the `login` field is never
  touched – that stays the operator's call), every unconfirmed opt-in token and every stored
  "remember me" login of the member is deleted, two-factor setup, backup codes, trusted devices and
  passkeys (where the Contao version has them) are reset, and a currently logged-in session of that same member is
  logged out. The previous holder could have set up any of them; the member signs back in via
  "lost password" and sets up the second factor again if needed. If another member meanwhile carries the address to be restored (such as a
  never activated registration created right afterwards), it is restored anyway and the operator
  gets a log entry about the duplicate – otherwise the revocation could be blocked that way. If the username cannot follow the restored
  address because somebody else carries it meanwhile (also with terminal42/contao-mailusername or
  on a simultaneous assignment), the revocation still goes through - address
  and password are a security function and must not fail over it; the username then stays as it is
  and the operator gets a log entry. Every failure shows the exact same generic message, regardless
  of the reason - a purely technical one included.
- **Overlapping requests:** a request of the previous holder that passed its session check just
  before the revocation may still write afterwards – a password, a "remember me" login, and on
  every login Contao (`User::save()`) even writes back the whole member row it loaded before,
  old address and old password included. Scripted back to back, such a request is nearly always in
  flight. As its last step, the revocation therefore leaves a marker in a table of its own
  (`tl_member_email_revoke`, out of reach of any write-back), with a fingerprint of the replaced
  password hash. At the end of every request of a logged-in member, the revocation's outcome is
  applied again under the row lock (address, username, anchor, pending tokens, credentials) if the
  request may have overlapped it (started at most 10 seconds after it, revocation at most two
  minutes old) or if the member row carries the replaced password hash again – which catches a
  write-back even later and from any request. The member is only hit if they already set a new
  password and signed in within those 10 seconds; after that, their new password has a new hash.
  An anonymous password reset ("lost password") that checked its link before the revocation and
  saves only after it loses the new password right away (hook `setNewPassword`: the member row
  then carries another address than the one the reset was granted for, and still the password it
  saved; a password the member has set meanwhile stays). If Contao fails with an error after saving
  and before the hook, this does not apply. Limits: the clocks of several web servers must stay
  within about 10 seconds; a
  write-back that also re-hashes the password (a change of hashing algorithm) is only caught by the
  time window. Cost: one indexed lookup per request of a logged-in member, a second one after a
  revocation within the last 24 hours. The daily cron deletes older markers; until
  `contao:migrate` has run, every such request logs an error.
- **A lost notice:** a failed send would leave the only way back unusable, so
  `emailChangeAnchorNotified` is set only once the mailer accepted the mail (with an
  asynchronous transport: handed over to the queue, whose own retries are the transport's business); an hourly cron re-sends
  the **same** already-issued link (from `emailChangeAnchorPending`) for valid, unnotified anchors
  - the deadline is never extended and the link never rotated, a duplicate send of the same link
  is harmless. When that cron runs on the CLI, `framework.router.default_uri` has to be set,
  otherwise the command line does not know the site's domain.
- **Locking protocol:** confirmation and revocation follow the same steps - transaction, member row
  lock (`SELECT ... FOR UPDATE`), then re-read the member and link rows freshly and with locking
  reads (the check for an address taken elsewhere and the username eligibility are not locking
  throughout), then
  commit link consumption, address, username, anchor and the cleanup of pending tokens together.
  Deliberately **without** a named `GET_LOCK`: a directory bundle that takes a named lock BEFORE
  this row lock would otherwise end up with the opposite lock order.
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
