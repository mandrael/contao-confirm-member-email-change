<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\MemberModel;
use Contao\ModulePersonalData;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameSyncListener;
use Symfony\Contracts\Translation\TranslatorInterface;

class UsernameSyncListenerTest extends ContaoTestCase
{
    /**
     * A1: proves the switch off leaves this new code path fully inert – part of
     * "Schalter aus = 1.0-Verhalten unverändert".
     */
    public function testDoesNothingWhenTheSwitchIsOff(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(false, $usernamePolicy);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $dc));
    }

    public function testRegistrationInvocationIsIgnored(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(true, $usernamePolicy);

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', null));
    }

    public function testFrontEndPendingChangeHasNoEffectiveNewValue(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(true, $usernamePolicy);
        $user = $this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7, 'username' => '', 'email' => 'old@example.com']);
        $module = $this->createStub(ModulePersonalData::class);

        // EmailChangeListener (priority 255) already suppressed the write and returned the
        // OLD address here, so this sees "no effective change".
        self::assertSame('old@example.com', $listener->onSaveEmail('old@example.com', $user, $module));
    }

    public function testFantasyUsernameIsNeverTouched(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(true, $usernamePolicy);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $dc));
    }

    public function testRejectsTheSaveWhenTheAddressIsNotEligible(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Collision);

        $listener = $this->listener(true, $usernamePolicy);
        $dc = $this->dataContainer(7, '', 'old@example.com');

        $this->expectException(\Exception::class);
        $listener->onSaveEmail('new@example.com', $dc);
    }

    public function testSyncsTheUsernameForAnEligibleBackEndChange(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        $member = $this->createMock(MemberModel::class);
        $member->expects(self::once())->method('setRow')->with(['username' => 'new@example.com'])->willReturn($member);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $listener = $this->listener(true, $usernamePolicy, $framework);
        $dc = $this->dataContainer(7, '', 'old@example.com');

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $dc));
    }

    private function listener(bool $enabled, UsernamePolicy $usernamePolicy, ?ContaoFramework $framework = null): UsernameSyncListener
    {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new UsernameSyncListener(
            $policy,
            $usernamePolicy,
            $this->createStub(TranslatorInterface::class),
            $framework ?? $this->createContaoFrameworkMock(),
        );
    }

    private function dataContainer(int $id, string $username, string $email): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => $id, 'username' => $username, 'email' => $email]);

        return $dc;
    }
}
