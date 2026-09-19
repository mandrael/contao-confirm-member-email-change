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
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The field callback only VALIDATES, the onsubmit callback WRITES - see the class
 * docblock of UsernameSyncListener for why (Codex 1: a write in the field callback runs
 * before the core's own uniqueness check).
 */
class UsernameSyncListenerTest extends ContaoTestCase
{
    /**
     * A1: proves the switch off leaves this new code path fully inert - part of
     * "Schalter aus = 1.0-Verhalten unverändert".
     */
    public function testDoesNothingWhenTheSwitchIsOff(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(false, $usernamePolicy);
        $dc = $this->dataContainer(7, 'johndoe', 'old@example.com');

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $dc));

        $listener->onSubmitMember($dc);
    }

    /**
     * Codex 1: the field callback must not write. DC_Table runs it BEFORE checking that
     * the email is unique, so a login name written here would survive a rejected email.
     */
    public function testTheFieldCallbackNeverWrites(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        $memberAdapter = $this->createAdapterMock(['findByPk']);
        $memberAdapter->expects(self::never())->method('findByPk');
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $listener = $this->listener(true, $usernamePolicy, $framework);

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', $this->dataContainer(7, 'johndoe', 'old@example.com')));
    }

    /**
     * Codex 4 (a): registration is invoked as ($value, null). An ineligible address is
     * thrown back, which makes ModuleRegistration add a widget error and skip the
     * insert - the member is never created without a login name.
     */
    public function testRegistrationWithAnIneligibleAddressIsRejected(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('a#b@example.com', null)->willReturn(EligibilityReason::Invalid);

        $this->expectException(\Exception::class);
        $this->listener(true, $usernamePolicy)->onSaveEmail('a#b@example.com', null);
    }

    public function testRegistrationWithAnEligibleAddressPassesThrough(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        self::assertSame('new@example.com', $this->listener(true, $usernamePolicy)->onSaveEmail('new@example.com', null));
    }

    /**
     * Codex 4 (b): the front-end request for a change is validated too. This listener
     * runs at priority 256, i.e. BEFORE EmailChangeListener swaps the value back to the
     * old address, so it sees the address the member really typed.
     */
    public function testFrontEndRequestWithAnIneligibleAddressIsRejected(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('a#b@example.com', 7)->willReturn(EligibilityReason::Invalid);

        $user = $this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7, 'username' => '', 'email' => 'old@example.com']);
        $module = $this->createStub(ModulePersonalData::class);

        $this->expectException(\Exception::class);
        $this->listener(true, $usernamePolicy)->onSaveEmail('a#b@example.com', $user, $module);
    }

    /**
     * Codex 4 (c): an UNCHANGED ineligible address must not block saving the member -
     * otherwise a legacy member can no longer be edited at all once the switch is on.
     */
    public function testAnUnchangedIneligibleAddressDoesNotBlockTheSave(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $listener = $this->listener(true, $usernamePolicy);

        self::assertSame('a#b@example.com', $listener->onSaveEmail('a#b@example.com', $this->dataContainer(7, 'johndoe', 'a#b@example.com')));
    }

    /**
     * Codex 4 (c), same case on the writing side: no throw, no write, one log entry
     * that names nothing but the member ID.
     */
    public function testAnUnchangedIneligibleAddressOnlyLeavesALogEntry(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Invalid);

        $memberAdapter = $this->createAdapterMock(['findByPk']);
        $memberAdapter->expects(self::never())->method('findByPk');
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::logicalAnd(
            self::stringContains('Member ID 7'),
            self::logicalNot(self::stringContains('a#b@example.com')),
        ));

        $this->listener(true, $usernamePolicy, $framework, $logger)->onSubmitMember($this->dataContainer(7, 'johndoe', 'a#b@example.com'));
    }

    /**
     * Codex 1: after a back end save the login name really has to be on the model when
     * save() runs - the old setRow() never marked anything as modified.
     */
    public function testWritesTheLoginNameAfterTheBackEndSave(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('New@Example.com', 7)->willReturn(EligibilityReason::Eligible);

        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'username' => 'johndoe']);
        $member->expects(self::once())->method('save')->willReturnCallback(
            static function () use ($member): void {
                self::assertSame('new@example.com', $member->username, 'the login name has to be set BEFORE save()');
            },
        );

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onSubmitMember($this->dataContainer(7, 'johndoe', 'New@Example.com'));

        self::assertSame('new@example.com', $member->username);
    }

    /**
     * Codex 4 (d): "already in sync" is an EXACT comparison against the canonical form.
     * strcasecmp would call "Anna@Example.com" in sync although the rule demands the
     * lower-cased address.
     */
    public function testAUsernameThatOnlyDiffersInCaseIsNotConsideredInSync(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'username' => 'Anna@Example.com']);
        $member->expects(self::once())->method('save');

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onSubmitMember($this->dataContainer(7, 'Anna@Example.com', 'Anna@Example.com'));

        self::assertSame('anna@example.com', $member->username);
    }

    public function testAnAlreadyCanonicalLoginNameIsLeftAlone(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $memberAdapter = $this->createAdapterMock(['findByPk']);
        $memberAdapter->expects(self::never())->method('findByPk');
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $this->listener(true, $usernamePolicy, $framework)->onSubmitMember($this->dataContainer(7, 'old@example.com', 'old@example.com'));
    }

    /**
     * The front end never writes: ModulePersonalData passes the user object, not a
     * DataContainer, and a pending change is indistinguishable from an unchanged email.
     */
    public function testTheFrontEndSubmitNeverWrites(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $user = $this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7, 'username' => 'johndoe', 'email' => 'old@example.com']);

        $this->listener(true, $usernamePolicy)->onSubmitMember($user);
    }

    /**
     * The validation only sees the address the member really typed if it runs BEFORE
     * EmailChangeListener swaps a pending front-end change back. Contao sorts DCA
     * callbacks by descending priority (core DataContainerCallbackListener.php:114,131),
     * so this invariant is a number in an attribute - and numbers get changed.
     */
    public function testValidatesBeforeThePendingChangeInterception(): void
    {
        self::assertGreaterThan(
            $this->callbackPriority(\Mandrael\ContaoConfirmMemberEmailChangeBundle\EventListener\EmailChangeListener::class, 'onSaveEmail'),
            $this->callbackPriority(UsernameSyncListener::class, 'onSaveEmail'),
        );
    }

    /**
     * A read that fails must never end up writing a default value.
     */
    public function testAFailedRecordReadWritesNothing(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        $this->listener(true, $usernamePolicy)->onSubmitMember($dc);
    }

    private function callbackPriority(string $class, string $method): int
    {
        $attribute = (new \ReflectionMethod($class, $method))->getAttributes(\Contao\CoreBundle\DependencyInjection\Attribute\AsCallback::class)[0] ?? null;

        self::assertNotNull($attribute, $class.'::'.$method.' must be registered as a DCA callback');

        return (int) $attribute->newInstance()->priority;
    }

    private function listener(
        bool $enabled,
        UsernamePolicy $usernamePolicy,
        ContaoFramework|null $framework = null,
        LoggerInterface|null $logger = null,
    ): UsernameSyncListener {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new UsernameSyncListener(
            $policy,
            $usernamePolicy,
            $this->createStub(TranslatorInterface::class),
            $framework ?? $this->createContaoFrameworkMock(),
            $logger,
        );
    }

    private function dataContainer(int $id, string $username, string $email): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => $id, 'username' => $username, 'email' => $email]);

        return $dc;
    }
}
