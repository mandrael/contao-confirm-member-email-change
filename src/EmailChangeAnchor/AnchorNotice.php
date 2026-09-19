<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Email;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A8: sends the revoke link to the old address and only THEN marks the anchor as
 * notified and clears the stashed plaintext (tl_member.emailChangeAnchorNotified /
 * emailChangeAnchorPending).
 *
 * The order matters: the plaintext link exists only in this mail and, for as long as the
 * send is pending, in emailChangeAnchorPending (see the DCA comment). A send that fails
 * after the change was committed would otherwise leave the member with an anchor they
 * can never use. Leaving emailChangeAnchorNotified at 0 hands the case to
 * ResendEmailChangeAnchorNoticeCron, which re-sends the SAME stashed link - Runde 2,
 * Befund 2: rotating it on every retry could invalidate a link that already arrived.
 *
 * Used by ConfirmEmailChangeController (right after its commit) and by that cron.
 */
class AnchorNotice
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function send(int $memberId, string $toEmail, string $plainToken): bool
    {
        if ('' === trim($toEmail)) {
            return false;
        }

        try {
            $email = $this->framework->createInstance(Email::class);
            $email->from = $GLOBALS['TL_ADMIN_EMAIL'] ?? null;
            $email->fromName = $GLOBALS['TL_ADMIN_NAME'] ?? null;
            $email->subject = $this->trans('revokeNoticeSubject');

            $url = $this->urlGenerator->generate(
                'mandrael_revoke_member_email_change',
                ['token' => $plainToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $email->text = \sprintf($this->trans('revokeNoticeText'), EmailChangeAnchorPolicy::ttlDays(), $url);

            $sent = (bool) $email->sendTo($toEmail);
        } catch (\Throwable) {
            $sent = false;
        }

        if (!$sent) {
            // No exception message in the log: it can carry the recipient address.
            $this->logger?->error(\sprintf('Could not send the email-change revoke notice for member ID %d. The anchor stays unnotified, a new link will be issued.', $memberId));

            return false;
        }

        // Clearing emailChangeAnchorPending in the SAME update the send success gates on:
        // the plaintext has done its job now that the mail is out.
        $this->connection->executeStatement(
            "UPDATE tl_member SET emailChangeAnchorNotified = 1, emailChangeAnchorPending = '' WHERE id = ? AND emailChangeAnchorHash = ?",
            [$memberId, EmailChangeAnchorPolicy::hashToken($plainToken)],
        );

        return true;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.confirmEmailChange.'.$key, [], 'contao_default');
    }
}
