<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EventListener;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\Email;
use Contao\FrontendUser;
use Contao\ModulePersonalData;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EventListener\EmailChangeListener;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\ConnectionMockTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailChangeListenerTest extends TestCase
{
    use ConnectionMockTrait;

    /**
     * The critical safety guard: when the address did not effectively change,
     * no opt-in token is created and the value is passed through untouched.
     */
    #[DataProvider('unchangedValues')]
    public function testReturnsValueUnchangedWhenEmailDidNotChange(string $submitted): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $listener = $this->listener($optIn);

        self::assertSame(
            $submitted,
            $listener->onSaveEmail($submitted, $this->frontendUser('member@example.com', 7), $this->createStub(ModulePersonalData::class)),
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function unchangedValues(): array
    {
        return [
            'identical' => ['member@example.com'],
            'case-insensitive match' => ['MEMBER@example.com'],
            'empty' => [''],
        ];
    }

    /**
     * The same DCA save_callback also runs during registration (as ($value, null))
     * and in the back end (as ($value, DataContainer)). Neither passes a
     * FrontendUser + ModulePersonalData, so the callback must return the value
     * untouched and never create a token – a narrow typed signature would fatal here.
     */
    public function testIgnoresRegistrationAndBackendInvocations(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $listener = $this->listener($optIn);

        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', null), 'registration ($value, null)');
        self::assertSame('new@example.com', $listener->onSaveEmail('new@example.com', new \stdClass()), 'back end ($value, DataContainer)');
    }

    /**
     * On a real change the token is NOT created in the field callback (it would be
     * wiped by the password field's opt-in purge); creation is deferred to onSubmit.
     * The field callback returns the OLD address to suppress the core write.
     */
    public function testChangedEmailIsDeferredAndSuppressesWrite(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $listener = $this->listener($optIn);

        self::assertSame(
            'old@example.com',
            $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class)),
        );
    }

    /**
     * onSubmit fires on every successful personal-data submit; without a pending
     * change stashed by onSaveEmail it must be a no-op (and must not create a token).
     */
    public function testOnSubmitIsNoopWithoutPendingChange(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $this->listener($optIn)->onSubmit();
    }

    /**
     * The listener is a shared service: a change stashed for one member must never be
     * issued in the onsubmit of another (long-running worker, failed validation between
     * the two callbacks), and reset() drops it between requests.
     */
    public function testAStashedChangeIsIssuedOnlyForItsOwnMember(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $listener = $this->listener($optIn);
        $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class));
        $listener->onSubmit($this->frontendUser('other@example.com', 8));

        $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class));
        $listener->reset();
        $listener->onSubmit($this->frontendUser('old@example.com', 7));
    }

    /**
     * DeepSeek W-4 (Runde 2): ModulePersonalData calls onsubmit_callbacks with no
     * try/catch of its own - a mail failure (typically: no effective administrator
     * address) must not escape onSubmit() as an uncaught exception. The confirmation
     * send and the old-address notice are independent failures.
     */
    public function testALostConfirmationSendDoesNotPreventTheOldAddressNotice(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->method('create')->willThrowException(new \RuntimeException('no administrator e-mail address'));

        $email = $this->createMock(Email::class);
        $email->expects(self::once())->method('sendTo')->with('old@example.com');

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($email);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::logicalAnd(
            self::stringContains('member ID 7'),
            self::logicalNot(self::stringContains('@example.com')),
        ));

        $listener = $this->listener($optIn, $framework, $logger);
        $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class));

        $listener->onSubmit($this->frontendUser('old@example.com', 7));
    }

    public function testALostOldAddressNoticeDoesNotPreventTheConfirmationSend(): void
    {
        $token = $this->createMock(OptInTokenInterface::class);
        $token->expects(self::once())->method('send');

        $optIn = $this->createMock(OptIn::class);
        $optIn->method('create')->willReturn($token);

        $email = $this->createMock(Email::class);
        $email->method('sendTo')->willThrowException(new \RuntimeException('smtp down'));

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($email);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::logicalAnd(
            self::stringContains('member ID 7'),
            self::logicalNot(self::stringContains('@example.com')),
        ));

        $listener = $this->listener($optIn, $framework, $logger);
        $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class));

        $listener->onSubmit($this->frontendUser('old@example.com', 7));
    }

    /**
     * A posted FORM_SUBMIT[] (array instead of scalar) must not turn the hook into an
     * uncaught TypeError/exception - InputBag::get() throws on a non-scalar value,
     * which is exactly why the source reads via all() instead.
     */
    public function testALoadLanguageFileWithFormSubmitAsAnArrayDoesNotThrowAndLeavesTheMessageUntouched(): void
    {
        $original = 'ERR.unique original';
        $GLOBALS['TL_LANG']['ERR']['unique'] = $original;
        $GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'] = 'email-specific message';

        try {
            $this->listener($this->createStub(OptIn::class), requestStack: $this->frontendRequestStack(['FORM_SUBMIT' => ['a', 'b']]))
                ->onLoadLanguageFile('default')
            ;

            self::assertSame($original, $GLOBALS['TL_LANG']['ERR']['unique']);
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    public function testALoadLanguageFileWithThePersonalDataFormSubmitReplacesTheMessage(): void
    {
        $GLOBALS['TL_LANG']['ERR']['unique'] = 'generic';
        $GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'] = 'email-specific message';

        try {
            $this->listener($this->createStub(OptIn::class), requestStack: $this->frontendRequestStack(['FORM_SUBMIT' => 'tl_member_5']))
                ->onLoadLanguageFile('default')
            ;

            self::assertSame('email-specific message', $GLOBALS['TL_LANG']['ERR']['unique']);
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    /**
     * A different frontend form with a unique field (e.g. registration, duplicate
     * username) must keep the generic message.
     */
    public function testALoadLanguageFileWithARegistrationFormSubmitKeepsTheGenericMessage(): void
    {
        $original = 'generic';
        $GLOBALS['TL_LANG']['ERR']['unique'] = $original;
        $GLOBALS['TL_LANG']['MSC']['confirmEmailChange']['emailExists'] = 'email-specific message';

        try {
            $this->listener($this->createStub(OptIn::class), requestStack: $this->frontendRequestStack(['FORM_SUBMIT' => 'tl_registration_5']))
                ->onLoadLanguageFile('default')
            ;

            self::assertSame($original, $GLOBALS['TL_LANG']['ERR']['unique']);
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function frontendRequestStack(array $post): RequestStack
    {
        $request = new Request([], $post);
        $request->attributes->set('_scope', ContaoCoreBundle::SCOPE_FRONTEND);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    /**
     * A revoke committed between the field callback and onSubmit resets the address and
     * purges every pending link. A link issued afterwards would take the account away
     * again, so onSubmit re-reads the address under the row lock and drops the request.
     */
    public function testDropsTheChangeWhenTheAddressChangedBeforeOnSubmit(): void
    {
        $optIn = $this->createMock(OptIn::class);
        $optIn->expects(self::never())->method('create');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('createInstance');

        $purger = $this->createMock(UnconfirmedTokenPurger::class);
        $purger->expects(self::never())->method('purge');

        $connection = $this->createConnectionMock(scalars: ['SELECT email FROM tl_member' => 'restored@example.com']);

        $listener = $this->listener($optIn, $framework, connection: $connection, purger: $purger);
        $listener->onSaveEmail('new@example.com', $this->frontendUser('old@example.com', 7), $this->createStub(ModulePersonalData::class));
        $listener->onSubmit($this->frontendUser('old@example.com', 7));

        self::assertSame('begin', $this->log[0]);
        self::assertStringContainsString('FOR UPDATE', $this->log[1]);
        self::assertSame('rollBack', $this->log[2]);
    }

    private function listener(OptIn $optIn, ContaoFramework|null $framework = null, LoggerInterface|null $logger = null, RequestStack|null $requestStack = null, Connection|null $connection = null, UnconfirmedTokenPurger|null $purger = null): EmailChangeListener
    {
        return new EmailChangeListener(
            $optIn,
            $framework ?? $this->createStub(ContaoFramework::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $requestStack ?? $this->createStub(RequestStack::class),
            $purger ?? $this->createStub(UnconfirmedTokenPurger::class),
            $connection ?? $this->createConnectionMock(scalars: ['SELECT email FROM tl_member' => 'old@example.com']),
            $logger,
        );
    }

    private function frontendUser(string $email, int $id): FrontendUser
    {
        $user = $this->createStub(FrontendUser::class);
        $user->method('__get')->willReturnMap([
            ['email', $email],
            ['id', $id],
        ]);

        return $user;
    }
}
