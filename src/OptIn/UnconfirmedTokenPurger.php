<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\OptInModel;

/**
 * Deletes every unconfirmed opt-in token of a member that starts with a given
 * prefix. Used both to replace an earlier unconfirmed "email" token
 * (EmailChangeListener, on a new change request) and, per A6, to revoke a
 * stale "pw" (core password-reset) token once an email change is confirmed –
 * the old channel must not be able to complete a reset for an address the
 * member no longer controls.
 */
class UnconfirmedTokenPurger
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function purge(int $memberId, string $prefix): void
    {
        $tokens = $this->framework
            ->getAdapter(OptInModel::class)
            ->findUnconfirmedByRelatedTableAndId('tl_member', $memberId)
        ;

        foreach ($tokens ?? [] as $model) {
            if (str_starts_with((string) $model->token, $prefix.'-')) {
                $model->delete();
            }
        }
    }
}
