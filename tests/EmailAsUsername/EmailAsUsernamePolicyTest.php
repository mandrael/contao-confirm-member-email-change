<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\Config;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;

class EmailAsUsernamePolicyTest extends ContaoTestCase
{
    /**
     * The default (a setting never explicitly turned on) must be OFF, so an
     * installation that never touches tl_settings keeps the 1.0 behaviour.
     */
    public function testIsDisabledByDefault(): void
    {
        $configAdapter = $this->createConfiguredAdapterMock(['get' => null]);
        $framework = $this->createContaoFrameworkMock([Config::class => $configAdapter]);

        self::assertFalse((new EmailAsUsernamePolicy($framework))->isEnabled());
    }

    public function testIsEnabledWhenTheSettingIsOn(): void
    {
        $configAdapter = $this->createConfiguredAdapterMock(['get' => true]);
        $framework = $this->createContaoFrameworkMock([Config::class => $configAdapter]);

        self::assertTrue((new EmailAsUsernamePolicy($framework))->isEnabled());
    }
}
