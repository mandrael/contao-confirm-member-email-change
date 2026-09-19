<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\ModulePersonalData;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
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
 *
 * Every write in onSubmitMember() goes through a single conditional UPDATE
 * (`id = ? AND email = ?`, Runde 2 Befund 3, blockierend) - a Model::save() call would
 * write unconditionally and could overwrite a login name a racing confirm/revoke had
 * just set from a different address.
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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $listener = $this->listener(false, $usernamePolicy, $connection);
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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $listener = $this->listener(true, $usernamePolicy, $connection);

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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::logicalAnd(
            self::stringContains('Member ID 7'),
            self::logicalNot(self::stringContains('a#b@example.com')),
        ));

        $this->listener(true, $usernamePolicy, $connection, $logger)->onSubmitMember($this->dataContainer(7, 'johndoe', 'a#b@example.com'));
    }

    /**
     * Codex 1/3: the back-end save writes a single conditional UPDATE, never an
     * unconditional Model::save() - and the WHERE clause pins the address just read.
     */
    public function testWritesTheLoginNameAfterTheBackEndSaveWithAConditionalUpdate(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('New@Example.com', 7)->willReturn(EligibilityReason::Eligible);

        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($this->dataContainer(7, 'johndoe', 'New@Example.com'));

        self::assertCount(1, $statements);
        self::assertStringContainsString('username = ?', $statements[0][0]);
        self::assertStringContainsString('id = ? AND email = ?', $statements[0][0], 'the write must be conditional on the address just read');
        self::assertSame(['new@example.com', $statements[0][1][1], 7, 'New@Example.com'], $statements[0][1]);
    }

    /**
     * Codex 3 (Runde 2, blockierend): zero affected rows - the address changed between
     * the read and this save (a racing confirm/revoke) - must not throw or retry. The
     * SQL condition alone prevents the stale write; the listener has nothing further to do.
     */
    public function testZeroAffectedRowsIsNotTreatedAsAnError(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Eligible);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($this->dataContainer(7, 'johndoe', 'New@Example.com'));

        // No exception, no second write attempt - reaching this line is the assertion.
        self::assertTrue(true);
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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::anything(),
            self::callback(static fn (array $params): bool => 'anna@example.com' === $params[0]),
        )->willReturn(1);

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($this->dataContainer(7, 'Anna@Example.com', 'Anna@Example.com'));
    }

    public function testAnAlreadyCanonicalLoginNameIsLeftAlone(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($this->dataContainer(7, 'old@example.com', 'old@example.com'));
    }

    /**
     * Codex 4 (a), Runde 2: the front end now DOES write - saving "personal data" (even
     * while an address change is still pending) has to sync a mismatched login name too,
     * not just registration/confirm/revoke.
     */
    public function testSyncsTheLoginNameOnAFrontEndPersonalDataSave(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('old@example.com', 7)->willReturn(EligibilityReason::Eligible);

        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        // Username deliberately "fantasy" (does not match the email), address unchanged -
        // EmailChangeListener already pinned $user->email to the SAVED address even while
        // a different change might be pending, see the class docblock.
        $user = $this->createClassWithPropertiesMock(FrontendUser::class, ['id' => 7, 'username' => 'johndoe', 'email' => 'old@example.com']);
        $module = $this->createStub(ModulePersonalData::class);

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($user, $module);

        self::assertSame(['old@example.com', $statements[0][1][1], 7, 'old@example.com'], $statements[0][1]);
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

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        $this->listener(true, $usernamePolicy, $connection)->onSubmitMember($dc);
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
        Connection|null $connection = null,
        LoggerInterface|null $logger = null,
    ): UsernameSyncListener {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new UsernameSyncListener(
            $policy,
            $usernamePolicy,
            $this->createStub(TranslatorInterface::class),
            $connection ?? $this->createStub(Connection::class),
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
