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

    public function testDoesNotOverwriteAUsernameTheRegistrantAlreadyTyped(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $this->listener(true, $usernamePolicy)->onCreateNewUser(3, ['email' => 'new@example.com', 'username' => 'johndoe']);
    }

    public function testSetsTheUsernameWhenEmptyAndEligible(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->with('new@example.com', 3)->willReturn(EligibilityReason::Eligible);

        $member = $this->createMock(MemberModel::class);
        $member->expects(self::once())->method('setRow')->with(['username' => 'new@example.com'])->willReturn($member);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onCreateNewUser(3, ['email' => 'new@example.com']);
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
