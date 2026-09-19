<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Controller;

use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\MemberModel;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller\ConfirmEmailChangeController;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers syncUsername() (private) via reflection: it is the confirm-time half of A3
 * (bullet 3) and the "1.0 behaviour when the opt-in is off" guarantee for this class.
 */
class ConfirmEmailChangeControllerTest extends ContaoTestCase
{
    /**
     * A1: proves the switch off leaves tl_member.username untouched when no
     * email-as-username extension is installed either – 1.0 behaviour, bitgenau.
     */
    public function testLeavesUsernameUntouchedWhenSwitchIsOffAndNoExtensionIsActive(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe']);
        $member->expects(self::never())->method('save');

        $changed = $this->syncUsername($this->controller(enabled: false), $member, 'new@example.com');

        self::assertFalse($changed);
        self::assertSame('johndoe', $member->username);
    }

    public function testFantasyUsernameIsNeverOverwrittenWhenEnabled(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe']);
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->expects(self::never())->method('evaluate');

        $changed = $this->syncUsername($this->controller(enabled: true, usernamePolicy: $usernamePolicy), $member, 'new@example.com');

        self::assertFalse($changed);
        self::assertSame('johndoe', $member->username);
    }

    public function testSyncsTheCanonicalUsernameWhenEmptyAndEligible(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => '']);
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->with('New@Example.com', 7)->willReturn(EligibilityReason::Eligible);

        $changed = $this->syncUsername($this->controller(enabled: true, usernamePolicy: $usernamePolicy), $member, 'New@Example.com');

        self::assertTrue($changed);
        self::assertSame('new@example.com', $member->username);
    }

    public function testDoesNotFailTheConfirmationWhenNotEligible(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => '']);
        $usernamePolicy = $this->createMock(UsernamePolicy::class);
        $usernamePolicy->method('evaluate')->willReturn(EligibilityReason::Collision);

        $changed = $this->syncUsername($this->controller(enabled: true, usernamePolicy: $usernamePolicy), $member, 'new@example.com');

        self::assertFalse($changed);
        self::assertSame('', $member->username);
    }

    /**
     * A6: a still-open core password-reset link for the OLD address must die with a
     * successful confirmation, so it cannot be used to take over the recovery channel.
     */
    public function testRevokesTheStaleCorePasswordResetTokenOnSuccess(): void
    {
        $member = $this->createClassWithPropertiesMock(MemberModel::class, ['id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe']);

        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member, 'findOneBy' => null]);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter]);

        $token = $this->createMock(OptInTokenInterface::class);
        $token->method('getIdentifier')->willReturn('email-abc123');
        $token->method('isConfirmed')->willReturn(false);
        $token->method('isValid')->willReturn(true);
        $token->method('getRelatedRecords')->willReturn(['tl_member' => [7]]);
        $token->method('getEmail')->willReturn('new@example.com');

        $optIn = $this->createMock(OptIn::class);
        $optIn->method('find')->with('email-abc123')->willReturn($token);

        $tokenPurger = $this->createMock(UnconfirmedTokenPurger::class);
        $tokenPurger->expects(self::once())->method('purge')->with(7, 'pw');

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn(false);

        $controller = new ConfirmEmailChangeController(
            $framework,
            $optIn,
            $this->createConfiguredAdapterStubTranslator(),
            $requestStack,
            $this->createStub(Security::class),
            $policy,
            $this->createMock(UsernamePolicy::class),
            $tokenPurger,
        );

        $response = $controller('email-abc123');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('new@example.com', $member->email);
    }

    private function createConfiguredAdapterStubTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function syncUsername(ConfirmEmailChangeController $controller, MemberModel $member, string $newEmail): bool
    {
        $method = new \ReflectionMethod($controller, 'syncUsername');

        return $method->invoke($controller, $member, $newEmail);
    }

    private function controller(bool $enabled, ?UsernamePolicy $usernamePolicy = null): ConfirmEmailChangeController
    {
        $policy = $this->createMock(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return new ConfirmEmailChangeController(
            $this->createContaoFrameworkMock(),
            $this->createStub(OptIn::class),
            $this->createStub(TranslatorInterface::class),
            new RequestStack(),
            $this->createStub(Security::class),
            $policy,
            $usernamePolicy ?? $this->createMock(UsernamePolicy::class),
            $this->createStub(UnconfirmedTokenPurger::class),
        );
    }
}
