<?php

declare(strict_types=1);

/*
 * A8: security-anchor fields for the "revoke via the old address" flow (see
 * EmailChangeAnchorPolicy, ConfirmEmailChangeController, RevokeEmailChangeController).
 * SQL-only - no backend palette entry; contao:migrate still creates the columns
 * because the schema provider reads every field's 'sql' key regardless of inputType.
 */

// sha256 hash of the revoke token; the plaintext exists only in the notification mail,
// never in the database or a log. BINARY so the lookup is a byte-exact match.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorHash'] = [
    'sql' => "char(64) BINARY NOT NULL default ''",
];

// The address the anchor restores to, i.e. the address BEFORE the confirmed change
// that created it.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorEmail'] = [
    'sql' => "varchar(255) NOT NULL default ''",
];

// Unix timestamp until the anchor is valid (EmailChangeAnchorPolicy::TTL_SECONDS).
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorExpires'] = [
    'sql' => 'int unsigned NOT NULL default 0',
];

// 1 once the revoke link really left the house. The plaintext token lives ONLY in that
// mail, so a failed send would otherwise destroy the single way back; while this stays 0
// ResendEmailChangeAnchorNoticeCron issues a fresh link for the same deadline.
$GLOBALS['TL_DCA']['tl_member']['fields']['emailChangeAnchorNotified'] = [
    'sql' => 'int unsigned NOT NULL default 0',
];
