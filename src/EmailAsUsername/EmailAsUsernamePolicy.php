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
    private static bool|null $terminal42Active = null;

    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function isEnabled(): bool
    {
        // terminal42/contao-mailusername owns tl_member.username as well (own column
        // definition merged at loadDataContainer time plus own save callbacks), so the
        // two must never write it at once. Deliberately NOT a composer "conflict": that
        // would lock existing installs of the published package out of an update. The
        // switch stays without effect instead, and the setting says so (tl_settings.php).
        if (self::isTerminal42Active()) {
            return false;
        }

        $this->framework->initialize();

        return (bool) $this->framework->getAdapter(Config::class)->get('memberEmailAsUsername');
    }

    public static function isTerminal42Active(): bool
    {
        // Memoized: isEnabled() runs on every member save and every login attempt, and a
        // class_exists() miss walks the autoloader every single time.
        return self::$terminal42Active ??= class_exists(\Terminal42\MailusernameBundle\Terminal42MailusernameBundle::class);
    }
}
