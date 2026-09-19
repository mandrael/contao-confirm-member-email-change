<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;

/**
 * A8: catches the anchors whose notification mail never made it out (see AnchorNotice).
 * Only the hash of the revoke token is stored, so the lost link cannot be resent – a NEW
 * token is issued instead: new secret, same target address, same deadline. The window is
 * never extended, an anchor stays exactly as long valid as the confirmed change made it.
 *
 * Hourly, not daily: this is the member's only way back after a hostile address change.
 */
#[AsCronJob('hourly')]
class ResendEmailChangeAnchorNoticeCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AnchorNotice $anchorNotice,
    ) {
    }

    public function __invoke(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, emailChangeAnchorHash, emailChangeAnchorEmail FROM tl_member WHERE emailChangeAnchorNotified = 0 AND emailChangeAnchorHash != '' AND emailChangeAnchorExpires > ?",
            [time()],
        );

        foreach ($rows as $row) {
            $memberId = (int) $row['id'];
            $token = bin2hex(random_bytes(32));

            // Conditional on the hash we just read: if the anchor changed in between (a
            // second confirmed change, a revoke, a parallel run of this cron), leave it
            // alone rather than overwrite a link that may already be on its way.
            $affected = $this->connection->executeStatement(
                'UPDATE tl_member SET emailChangeAnchorHash = ? WHERE id = ? AND emailChangeAnchorHash = ? AND emailChangeAnchorNotified = 0',
                [EmailChangeAnchorPolicy::hashToken($token), $memberId, (string) $row['emailChangeAnchorHash']],
            );

            if (0 === $affected) {
                continue;
            }

            $this->anchorNotice->send($memberId, (string) $row['emailChangeAnchorEmail'], $token);
        }
    }
}
