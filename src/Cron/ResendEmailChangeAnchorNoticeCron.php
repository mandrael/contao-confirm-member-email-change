<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorNotice;

/**
 * A8: catches the anchors whose notification mail never made it out (see AnchorNotice).
 *
 * Runde 2, Befund 2: this used to rotate the anchor on every retry, which could
 * invalidate a link that had already reached the mailbox (two overlapping runs, or a
 * crash between issuing the new hash and marking the old one notified). It now NEVER
 * rotates a valid anchor - it re-sends the exact same plaintext link stashed in
 * emailChangeAnchorPending while the send is still pending (emailChangeAnchorNotified =
 * 0). A second successful send of the same link is harmless and accepted; the deadline
 * (emailChangeAnchorExpires) is never touched either way.
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
            "SELECT id, emailChangeAnchorEmail, emailChangeAnchorPending FROM tl_member WHERE emailChangeAnchorNotified = 0 AND emailChangeAnchorHash != '' AND emailChangeAnchorPending != '' AND emailChangeAnchorExpires > ?",
            [time()],
        );

        foreach ($rows as $row) {
            // AnchorNotice re-checks emailChangeAnchorHash on its own write, so a
            // meanwhile-replaced or revoked anchor simply fails its conditional UPDATE.
            $this->anchorNotice->send((int) $row['id'], (string) $row['emailChangeAnchorEmail'], (string) $row['emailChangeAnchorPending']);
        }
    }
}
