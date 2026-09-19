<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;

/**
 * A1: reads the tl_settings opt-in switch. Default (unset) is OFF, so an
 * installation that never touches the new setting keeps the 1.0 behaviour.
 */
class EmailAsUsernamePolicy
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function isEnabled(): bool
    {
        $this->framework->initialize();

        return (bool) $this->framework->getAdapter(Config::class)->get('memberEmailAsUsername');
    }
}
