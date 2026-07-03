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
   notice. The profile keeps showing the **old** address (no lockout, login still works).
3. The member opens the link → a thin controller confirms the token, writes the new address and
   – if an email-as-username extension is active – keeps the username in sync.

## Installation

```bash
composer require mandrael/contao-confirm-member-email-change
```

The bundle registers itself via the Contao Manager Plugin — **no further configuration
needed**. After clicking the confirmation link the member sees a short confirmation page.
If an email login is active (see below), they are logged out in the process and then sign in
with the new address.

## Compatibility: email as username

It is **either/or** – both extensions solve the same task and are **not** installed together:

| Extension | Behaviour with this bundle |
|---|---|
| [**terminal42/contao-mailusername**](https://github.com/terminal42/contao-mailusername) (recommended) | Pure sync `username = email`. On confirmation the username is carried over **verbatim** (otherwise login with the new address would break). |
| [heimrichhannot/contao-email2username-bundle](https://github.com/heimrichhannot/contao-email2username-bundle) | Sync **+** login decorator. Login works regardless; the username is carried over cosmetically (lowercase). |

Without such an extension, `tl_member.username` is left untouched.

## Compatibility

| | Version |
|---|---|
| Contao | `^5.3` (5.3 LTS … 5.7 LTS) |
| PHP | `^8.1` |
| Symfony | `^6.4 \|\| ^7.0` |

## License

MIT
