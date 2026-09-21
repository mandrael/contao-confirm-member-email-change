<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Security;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Security\EmailAsUsernameLoginListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

class EmailAsUsernameLoginListenerTest extends ContaoTestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestStack = new RequestStack();
    }

    /**
     * Runde 2, Befund 5 (DeepSeek BL-1): normalization is now independent of the
     * memberEmailAsUsername switch - flipped from the former
     * testDoesNothingWhenTheSwitchIsOff, which cemented the opposite.
     */
    public function testNormalizesEvenWhenTheSwitchIsOff(): void
    {
        $event = $this->event('Member@Example.com', frontend: true);

        $this->listener()->__invoke($event);

        self::assertSame('member@example.com', $this->identifier($event));
    }

    public function testDoesNothingOnTheBackendFirewall(): void
    {
        $event = $this->event('Member@Example.com', frontend: false);

        $this->listener()->__invoke($event);

        self::assertSame('Member@Example.com', $this->identifier($event));
    }

    public function testDoesNothingWithoutAnAtSign(): void
    {
        $event = $this->event('johndoe', frontend: true);

        $this->listener()->__invoke($event);

        self::assertSame('johndoe', $this->identifier($event));
    }

    /**
     * A7: an existing, exactly-cased username is never shadowed by the lowercase fallback.
     */
    public function testKeepsTheExactIdentifierWhenAMemberCarriesIt(): void
    {
        $event = $this->event('Member@Example.com', frontend: true);

        $this->listener(exactMatchExists: true)->__invoke($event);

        self::assertSame('Member@Example.com', $this->identifier($event));
    }

    public function testFallsBackToLowercaseWhenNoExactMatchExists(): void
    {
        $event = $this->event('Member@Example.com', frontend: true);

        $this->listener(exactMatchExists: false)->__invoke($event);

        self::assertSame('member@example.com', $this->identifier($event));
    }

    /**
     * Same rule the stored login name follows (CanonicalUsername): an IDN domain
     * normalizes to punycode, not just lowercase.
     */
    public function testNormalizesAUnicodeDomainToPunycode(): void
    {
        $event = $this->event('Anna@MÜLLER.example', frontend: true);

        $this->listener(exactMatchExists: false)->__invoke($event);

        self::assertSame('anna@xn--mller-kva.example', $this->identifier($event));
    }

    private function listener(bool $exactMatchExists = false): EmailAsUsernameLoginListener
    {
        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher->method('isFrontendRequest')->willReturnCallback(
            static fn (Request $request): bool => 'frontend' === $request->attributes->get('_test_scope'),
        );

        $userAdapter = $this->createConfiguredAdapterMock([
            'loadUserByIdentifier' => $exactMatchExists ? $this->createStub(FrontendUser::class) : null,
        ]);
        $framework = $this->createContaoFrameworkMock([FrontendUser::class => $userAdapter]);

        return new EmailAsUsernameLoginListener($scopeMatcher, $this->requestStack, $framework);
    }

    private function event(string $identifier, bool $frontend): CheckPassportEvent
    {
        $request = new Request();
        $request->attributes->set('_test_scope', $frontend ? 'frontend' : 'backend');

        $this->requestStack->push($request);

        $badge = new UserBadge($identifier, static fn (string $id): FrontendUser => throw new \LogicException('not needed in this test'));
        $passport = new Passport($badge, new PasswordCredentials('secret'));

        return new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport);
    }

    private function identifier(CheckPassportEvent $event): string
    {
        return $event->getPassport()->getBadge(UserBadge::class)->getUserIdentifier();
    }
}
