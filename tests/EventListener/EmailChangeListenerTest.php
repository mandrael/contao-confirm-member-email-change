<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptIn;
use Contao\FrontendUser;
use Contao\ModulePersonalData;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EventListener\EmailChangeListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailChangeListenerTest extends TestCase
{
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
     * untouched and never create a token — a narrow typed signature would fatal here.
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

    private function listener(OptIn $optIn): EmailChangeListener
    {
        return new EmailChangeListener(
            $optIn,
            $this->createStub(ContaoFramework::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(RequestStack::class),
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
