<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * A8: clears expired security-anchor fields (see EmailChangeAnchorPolicy) so an old
 * address does not sit in tl_member indefinitely once its 14-day window has passed.
 * A used anchor is already cleared synchronously by RevokeEmailChangeController; this
 * only catches the ones nobody ever clicked. One indexed UPDATE, no per-row model
 * loop – mirrors the core's own PurgeOptInTokensCron.
 */
#[AsCronJob('daily')]
class PurgeExpiredEmailChangeAnchorsCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(): void
    {
        $affected = $this->connection->executeStatement(
            "UPDATE tl_member SET emailChangeAnchorHash = '', emailChangeAnchorEmail = '', emailChangeAnchorExpires = 0 WHERE emailChangeAnchorHash != '' AND emailChangeAnchorExpires <= ?",
            [time()],
        );

        if ($affected > 0) {
            $this->logger?->info(\sprintf('Purged %d expired email-change security anchor(s)', $affected));
        }
    }
}
