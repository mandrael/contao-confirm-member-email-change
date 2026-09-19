<?php

declare(strict_types=1);

/*
 * Language keys for the email-change confirmation.
 * %s in the *text keys is a sprintf placeholder (link resp. new address).
 */

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['subject'] = 'Please confirm your new email address';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['text'] = "You have entered a new email address for your account.\n\nPlease confirm the change by opening this link:\n\n%s\n\nThe link is valid for 24 hours. Your current address stays active until you confirm. If this wasn't you, simply ignore this email.";

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['noticeSubject'] = 'Change of your email address requested';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['noticeText'] = "A change of the email address on your account to %s has been requested.\n\nThe change only takes effect once it is confirmed via the link sent to the new address. If you did not request this, no action is needed – your current address stays unchanged.";

$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['pending'] = 'We have sent a confirmation link to %s. Your email address will only change after you click that link.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['success'] = 'Your new email address has been confirmed and is now active.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['expired'] = 'This confirmation link is no longer valid. Please request the change again.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['alreadyConfirmed'] = 'This email change has already been confirmed.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['invalid'] = 'The confirmation link is invalid.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['taken'] = 'This email address is meanwhile already in use by another account.';

// Titles + link for the self-contained confirmation page
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['successTitle'] = 'Email address confirmed';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['errorTitle'] = 'Confirmation not possible';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['backToSite'] = 'Back to the homepage';

// Replaces Contao's generic uniqueness error in the FE profile
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'] = 'This email address already exists.';

// A2 (email as username opt-in): rejection when the address cannot be used as a login name
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['usernameRejected'] = 'This address cannot be used as a login name.';

// A8: security anchor - second notice to the old address AFTER confirmation.
// %d = validity in days (EmailChangeAnchorPolicy::ttlDays()), %s = revoke link.
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeNoticeSubject'] = 'Your email address has been changed';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeNoticeText'] = "Your email address has been changed. Wasn't you? This link undoes the change (valid for %d days):\n\n%s";
// Chained case: an older, still-valid anchor is kept - this address deliberately gets
// NO link (it might belong to the attacker).
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeNoticeChainedText'] = 'Your email address has been changed. A revocation for this account is already possible via an earlier notice.';

// A8: confirmation page (GET) and the outcome of the revocation (POST).
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeFormTitle'] = 'Revoke the email change';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeFormText'] = 'Do you want to undo the most recent change of your email address? Your previous address will be restored and your password invalidated.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeButton'] = 'Revoke the change';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeSuccessTitle'] = 'Change revoked';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeSuccess'] = 'The change has been undone. Please set a new password.';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeErrorTitle'] = 'Revocation not possible';
$GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['revokeInvalid'] = 'The link is invalid or has expired.';
