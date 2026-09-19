<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\MemberModel;
use Contao\System;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\RegistrationUsernameListener;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;

class RegistrationUsernameListenerTest extends ContaoTestCase
{
    public function testDoesNothingWhenTheSwitchIsOff(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $this->listener(false, $usernamePolicy)->onCreateNewUser(3, ['email' => 'new@example.com']);
    }

    /**
     * Runde 2, Befund 4(b): "username IS the email" has no exception for a pre-filled
     * value - a legacy module config or a still-posted field must not survive.
     */
    public function testOverwritesAUsernameTheRegistrantAlreadyTyped(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->with('new@example.com', 3)->willReturn(EligibilityReason::Eligible);

        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 3, 'username' => 'johndoe', 'email' => 'new@example.com']);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onCreateNewUser(3, ['email' => 'new@example.com', 'username' => 'johndoe']);

        self::assertSame('new@example.com', $member->username);
    }

    /**
     * Codex 1: the model must carry the new login name when save() is called. Asserting
     * on setRow() was exactly what hid the bug - setRow() replaces the whole row and
     * marks nothing as modified, so save() wrote nothing at all.
     */
    public function testSetsTheUsernameWhenEmptyAndEligible(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->with('New@Example.com', 3)->willReturn(EligibilityReason::Eligible);

        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 3, 'username' => null, 'email' => 'New@Example.com']);
        $member->expects(self::once())->method('save')->willReturnCallback(
            static function () use ($member): void {
                self::assertSame('new@example.com', $member->username, 'the login name has to be set BEFORE save()');
            },
        );

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onCreateNewUser(3, ['email' => 'New@Example.com']);

        self::assertSame('new@example.com', $member->username);
    }

    public function testLeavesTheUsernameEmptyWhenNotEligible(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Collision);

        $memberAdapter = $this->createAdapterMock(['findByPk']);
        $memberAdapter->expects(self::never())->method('findByPk');
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onCreateNewUser(3, ['email' => 'new@example.com']);
    }

    public function testEditableOptionsHideUsernameWhenTheSwitchIsOn(): void
    {
        $systemAdapter = $this->createAdapterMock(['importStatic']);
        $systemAdapter->method('importStatic')->with('tl_module')->willReturn(new class {
            public function getEditableMemberProperties(): array
            {
                return ['username' => 'Username', 'firstname' => 'First name'];
            }
        });

        $framework = $this->createContaoFrameworkMock([System::class => $systemAdapter]);
        $usernamePolicy = $this->createMock(UsernamePolicy::class);

        $listener = $this->listener(true, $usernamePolicy, $framework);
        $options = $listener->getEditableMemberProperties($this->createMock(DataContainer::class));

        self::assertArrayNotHasKey('username', $options);
        self::assertArrayHasKey('firstname', $options);
    }

    private function listener(bool $enabled, UsernamePolicy $usernamePolicy, ?ContaoFramework $framework = null): RegistrationUsernameListener
    {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new RegistrationUsernameListener(
            $policy,
            $usernamePolicy,
            $framework ?? $this->createContaoFrameworkMock(),
        );
    }
}
