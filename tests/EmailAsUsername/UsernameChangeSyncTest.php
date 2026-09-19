<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\MemberModel;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;

/**
 * A3 (bullet 3) forward direction (confirm: old email -> new email) via apply(), and
 * A8 backward direction (revoke: compromised email -> restored old email) via
 * resolve() directly - both controllers share the exact same rule, this is its
 * single test surface.
 */
class UsernameChangeSyncTest extends ContaoTestCase
{
    /**
     * A1: proves the switch off leaves the username untouched when no email-as-username
     * extension is installed either - 1.0 behaviour, bitgenau.
     */
    public function testLeavesUsernameUntouchedWhenSwitchIsOffAndNoExtensionIsActive(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe']);
        $member->expects(self::never())->method('save');

        $changed = $this->sync(enabled: false)->apply($member, 'new@example.com');

        self::assertFalse($changed);
        self::assertSame('johndoe', $member->username);
    }

    public function testFantasyUsernameIsNeverOverwrittenWhenEnabled(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $resolved = $this->sync(enabled: true, usernamePolicy: $usernamePolicy)->resolve('johndoe', 'old@example.com', 'new@example.com', 7);

        self::assertNull($resolved);
    }

    public function testResolvesTheCanonicalUsernameWhenEmptyAndEligible(): void
    {
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('New@Example.com', 7)->willReturn(EligibilityReason::Eligible);

        $resolved = $this->sync(enabled: true, usernamePolicy: $usernamePolicy)->resolve('', 'old@example.com', 'New@Example.com', 7);

        self::assertSame('new@example.com', $resolved);
    }

    public function testResolvesTheCanonicalUsernameWhenItStillMatchesTheReplacedEmail(): void
    {
        // A8 backward direction: the username still equals the COMPROMISED address
        // being replaced by the restore -> it must follow back to the restored one.
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::once())->method('evaluate')->with('victim@example.com', 7)->willReturn(EligibilityReason::Eligible);

        $resolved = $this->sync(enabled: true, usernamePolicy: $usernamePolicy)->resolve(
            'compromised@example.com',
            'compromised@example.com',
            'victim@example.com',
            7,
        );

        self::assertSame('victim@example.com', $resolved);
    }

    public function testDoesNotResolveWhenNotEligible(): void
    {
        $usernamePolicy = $this->createStub(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Collision);

        $resolved = $this->sync(enabled: true, usernamePolicy: $usernamePolicy)->resolve('', 'old@example.com', 'new@example.com', 7);

        self::assertNull($resolved);
    }

    /**
     * terminal42/contao-mailusername fallback: verbatim sync with NO follow-rule
     * guard (the extension has no login decorator, so the username MUST follow or
     * login with the new address breaks) - simulated here via the opt-in switch off
     * and no extension class present, which is the only branch reachable in a test
     * environment without the extension installed; the guard-skip itself is
     * documented on UsernameChangeSync::resolve().
     */
    public function testReturnsNullWhenSwitchIsOffAndNoExtensionIsActiveEvenForAFantasyUsername(): void
    {
        $resolved = $this->sync(enabled: false)->resolve('johndoe', 'old@example.com', 'new@example.com', 7);

        self::assertNull($resolved);
    }

    private function sync(bool $enabled, ?UsernamePolicy $usernamePolicy = null): UsernameChangeSync
    {
        $policy = $this->createStub(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new UsernameChangeSync($policy, $usernamePolicy ?? $this->createStub(UsernamePolicy::class));
    }
}
