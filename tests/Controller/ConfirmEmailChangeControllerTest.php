<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Controller;

use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\Email;
use Contao\MemberModel;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Controller\ConfirmEmailChangeController;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameChangeSync;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The username-follow decision itself (A2/A3) is covered by UsernameChangeSyncTest -
 * this class covers what is specific to the controller: revoking the stale core
 * password-reset token on a successful confirm (A6) and the A8 security-anchor
 * lifecycle (create / chain / notice with-or-without a link).
 */
class ConfirmEmailChangeControllerTest extends ContaoTestCase
{
    /**
     * A6: a still-open core password-reset link for the OLD address must die with a
     * successful confirmation, so it cannot be used to take over the recovery channel.
     */
    public function testRevokesTheStaleCorePasswordResetTokenOnSuccess(): void
    {
        $member = $this->member(['id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe']);

        $tokenPurger = $this->createMock(UnconfirmedTokenPurger::class);
        $purged = [];
        $tokenPurger->expects(self::exactly(2))->method('purge')->willReturnCallback(
            static function (int $memberId, string $prefix) use (&$purged): void {
                self::assertSame(7, $memberId);
                $purged[] = $prefix;
            },
        );

        $response = $this->invokeController($member, $tokenPurger);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('new@example.com', $member->email);
        self::assertSame(['pw', 'mdacc'], $purged);
    }

    /**
     * A8: no anchor exists yet -> a fresh one is created (hash of a random token,
     * OLD address, a 14-day expiry) and the old address gets the notice WITH the
     * revoke link.
     */
    public function testCreatesAFreshAnchorAndSendsTheLinkedNoticeWhenNoneExists(): void
    {
        $member = $this->member([
            'id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe',
            'emailChangeAnchorHash' => '', 'emailChangeAnchorEmail' => '', 'emailChangeAnchorExpires' => 0,
        ]);

        $before = time();
        $this->invokeController($member);
        $after = time();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $member->emailChangeAnchorHash);
        self::assertSame('old@example.com', $member->emailChangeAnchorEmail);
        self::assertGreaterThanOrEqual($before + EmailChangeAnchorPolicy::TTL_SECONDS, (int) $member->emailChangeAnchorExpires);
        self::assertLessThanOrEqual($after + EmailChangeAnchorPolicy::TTL_SECONDS, (int) $member->emailChangeAnchorExpires);
    }

    /**
     * A8 chain rule: a still-valid anchor from an earlier change must NOT be
     * overwritten by a second confirmed change - otherwise an attacker who just took
     * over the account could erase the real owner's own way back in. The old address
     * of THIS second change gets the notice WITHOUT a link instead.
     */
    public function testKeepsAnExistingValidAnchorUnchanged(): void
    {
        $member = $this->member([
            'id' => 7, 'email' => 'compromised@example.com', 'username' => 'johndoe',
            'emailChangeAnchorHash' => str_repeat('a', 64),
            'emailChangeAnchorEmail' => 'victim@example.com',
            'emailChangeAnchorExpires' => time() + 3600,
        ]);

        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->expects(self::once())->method('sendTo')->with('compromised@example.com');

        $this->invokeController($member, null, $email);

        self::assertSame(str_repeat('a', 64), $member->emailChangeAnchorHash);
        self::assertSame('victim@example.com', $member->emailChangeAnchorEmail);
        self::assertSame('MSC.confirmEmailChange.revokeNoticeChainedText', $email->text);
    }

    /**
     * A8: an EXPIRED anchor is not "still valid" - the chain rule does not apply and
     * a fresh anchor replaces it (with the linked notice again).
     */
    public function testReplacesAnExpiredAnchor(): void
    {
        $member = $this->member([
            'id' => 7, 'email' => 'old@example.com', 'username' => 'johndoe',
            'emailChangeAnchorHash' => str_repeat('a', 64),
            'emailChangeAnchorEmail' => 'ancient@example.com',
            'emailChangeAnchorExpires' => time() - 1,
        ]);

        $email = $this->createPartialMock(Email::class, ['sendTo']);
        $email->expects(self::once())->method('sendTo')->with('old@example.com');

        $this->invokeController($member, null, $email);

        self::assertNotSame(str_repeat('a', 64), $member->emailChangeAnchorHash);
        self::assertSame('old@example.com', $member->emailChangeAnchorEmail);
        self::assertSame('MSC.confirmEmailChange.revokeNoticeText', $email->text);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function member(array $properties): MemberModel
    {
        return $this->createClassWithPropertiesMock(MemberModel::class, $properties);
    }

    private function invokeController(MemberModel $member, ?UnconfirmedTokenPurger $tokenPurger = null, ?Email $email = null): Response
    {
        $memberAdapter = $this->createConfiguredAdapterMock(['findByPk' => $member, 'findOneBy' => null]);
        $email ??= $this->createPartialMock(Email::class, ['sendTo']);
        $framework = $this->createContaoFrameworkMock([MemberModel::class => $memberAdapter], [Email::class => $email]);

        $token = $this->createStub(OptInTokenInterface::class);
        $token->method('getIdentifier')->willReturn('email-abc123');
        $token->method('isConfirmed')->willReturn(false);
        $token->method('isValid')->willReturn(true);
        $token->method('getRelatedRecords')->willReturn(['tl_member' => [7]]);
        $token->method('getEmail')->willReturn('new@example.com');

        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::once())->method('find')->with('email-abc123')->willReturn($token);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        // No email-as-username extension active in the test environment -> username
        // stays untouched, which keeps these tests focused on the anchor/notice logic.
        $emailAsUsernamePolicy = $this->createStub(EmailAsUsernamePolicy::class);
        $emailAsUsernamePolicy->method('isEnabled')->willReturn(false);
        $usernameChangeSync = new UsernameChangeSync($emailAsUsernamePolicy, $this->createStub(UsernamePolicy::class));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new ConfirmEmailChangeController(
            $framework,
            $optIn,
            $translator,
            $requestStack,
            $this->createStub(Security::class),
            $usernameChangeSync,
            $tokenPurger ?? $this->createStub(UnconfirmedTokenPurger::class),
            $this->createStub(UrlGeneratorInterface::class),
        );

        return $controller('email-abc123');
    }
}
