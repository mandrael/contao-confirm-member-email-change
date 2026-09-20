<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameFieldLockListener;

class UsernameFieldLockListenerTest extends ContaoTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_member'], $GLOBALS['BE_FFL']['mandraelUsernameLocked'], $GLOBALS['TL_FFL']['mandraelUsernameLocked']);

        parent::tearDown();
    }

    public function testLocksTheUsernameFieldWhenTheSwitchIsOn(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType'] = 'text';

        $this->listener(true)->onLoadDataContainer('tl_member');

        $lockedType = $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType'];

        self::assertNotSame('text', $lockedType);

        // The exact gate DataContainer::row()/ModulePersonalData/ModuleRegistration all
        // share: the widget class resolved from the (now locked) inputType must not exist.
        self::assertArrayHasKey($lockedType, $GLOBALS['BE_FFL']);
        self::assertArrayHasKey($lockedType, $GLOBALS['TL_FFL']);
        self::assertFalse(class_exists((string) $GLOBALS['BE_FFL'][$lockedType]));
        self::assertFalse(class_exists((string) $GLOBALS['TL_FFL'][$lockedType]));
    }

    public function testLeavesTheInputTypeUntouchedWhenTheSwitchIsOff(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType'] = 'text';

        $this->listener(false)->onLoadDataContainer('tl_member');

        self::assertSame('text', $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType']);
    }

    public function testIgnoresEveryOtherTable(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType'] = 'text';

        $this->listener(true)->onLoadDataContainer('tl_page');

        self::assertSame('text', $GLOBALS['TL_DCA']['tl_member']['fields']['username']['inputType']);
    }

    private function listener(bool $enabled): UsernameFieldLockListener
    {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new UsernameFieldLockListener($policy);
    }
}
