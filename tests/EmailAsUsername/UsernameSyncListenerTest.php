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

    /**
     * A "fantasy" username no longer survives - the follow rule that protected it was
     * deliberately removed (Auftraggeber, 19.09.2026: username IS email, hard requirement).
     */
    public function testOverwritesAFantasyUsernameWhenTheEmailChanges(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        $member = $this->createMock(MemberModel::class);
        $member->expects(self::once())->method('setRow')->with(['username' => 'new@example.com'])->willReturn($member);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $listener = $this->listener(true, $usernamePolicy, $framework);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $dc));
    }

    /**
     * BE only: even when the email itself is unchanged, a stale/mismatched username is
     * corrected (e.g. the switch was only just turned on) - the early "no effective change"
     * exit must not shield a mismatched username from being fixed.
     */
    public function testFixesAMismatchedUsernameOnTheBackEndEvenWhenTheEmailIsUnchanged(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('old@example.com', 7)->willReturn(EligibilityReason::Eligible);

        $member = $this->createMock(MemberModel::class);
        $member->expects(self::once())->method('setRow')->with(['username' => 'old@example.com'])->willReturn($member);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $listener = $this->listener(true, $usernamePolicy, $framework);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('old@example.com', $listener->onSaveEmail('old@example.com', $dc));
    }

    /**
     * A1: the switch off leaves a mismatched username untouched too - not just an actual
     * email change, part of "Schalter aus = 1.0-Verhalten unverändert".
     */
    public function testDoesNothingWhenTheSwitchIsOffEvenWithAMismatchedUsername(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(false, $usernamePolicy);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('old@example.com', $listener->onSaveEmail('old@example.com', $dc));
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
