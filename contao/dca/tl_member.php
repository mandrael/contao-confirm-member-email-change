<?php

declare(strict_types=1);

/*
 * A8: security-anchor fields for the "revoke via the old address" flow (see
 * EmailChangeAnchorPolicy, ConfirmEmailChangeController, RevokeEmailChangeController).
 * SQL-only - no backend palette entry; contao:migrate still creates the columns
 * because the schema provider reads every field's 'sql' key regardless of inputType.
 */

// Codex Runde 2, Befund 1 (blockierend): DC_Table::copy() copies every field verbatim
// unless it carries eval.doNotCopy - without it, copying a member would copy a still
// valid revoke anchor too, and both rows would answer to the same link. All five anchor
// fields below reset to their SQL default on copy.
$doNotCopy = ['eval' => ['doNotCopy' => true]];

// sha256 hash of the revoke token; the plaintext exists only in the notification mail
// and, for as long as its send is pending, in emailChangeAnchorPending below - never in
// a log. BINARY so the lookup is a byte-exact match.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorHash'] = $doNotCopy + [
    'sql' => "char(64) BINARY NOT NULL default ''",
];

// The address the anchor restores to, i.e. the address BEFORE the confirmed change
// that created it.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorEmail'] = $doNotCopy + [
    'sql' => "varchar(255) NOT NULL default ''",
];

// Unix timestamp until the anchor is valid (EmailChangeAnchorPolicy::TTL_SECONDS).
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorExpires'] = $doNotCopy + [
    'sql' => 'int unsigned NOT NULL default 0',
];

// 1 once the revoke link really left the house. While this stays 0,
// ResendEmailChangeAnchorNoticeCron re-sends the SAME link stored in
// emailChangeAnchorPending rather than issuing a new one (Runde 2, Befund 2): rotating
// the anchor on every retry could invalidate a link that already reached the mailbox.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorNotified'] = $doNotCopy + [
    'sql' => 'int unsigned NOT NULL default 0',
];

// The plaintext revoke token, held ONLY for as long as its send is pending
// (emailChangeAnchorNotified = 0). Contao's own tl_opt_in stores confirmation links in
// plaintext the same way (OptInModel.token); this mirrors that, scoped to the pending
// window only - cleared the moment the mail goes out (AnchorNotice), on revoke, on
// expiry (PurgeExpiredEmailChangeAnchorsCron) and overwritten whenever a fresh anchor
// replaces this one.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorPending'] = $doNotCopy + [
    'sql' => "varchar(64) NOT NULL default ''",
];
