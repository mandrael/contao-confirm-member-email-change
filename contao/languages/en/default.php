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
