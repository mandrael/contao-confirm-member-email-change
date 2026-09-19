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
 * notified (tl_member.emailChangeAnchorNotified).
 *
 * The order matters: only the hash of the token is stored, so the plaintext link exists
 * nowhere but in this mail. A send that fails after the change was committed would
 * otherwise leave the member with an anchor they can never use. Leaving the column at 0
 * hands the case to ResendEmailChangeAnchorNoticeCron, which issues a NEW token for the
 * same deadline.
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

        $this->connection->executeStatement(
            'UPDATE tl_member SET emailChangeAnchorNotified = 1 WHERE id = ? AND emailChangeAnchorHash = ?',
            [$memberId, EmailChangeAnchorPolicy::hashToken($plainToken)],
        );

        return true;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('MSC.confirmEmailChange.'.$key, [], 'contao_default');
    }
}
