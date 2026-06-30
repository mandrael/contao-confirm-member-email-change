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

        $listener = new EmailChangeListener(
            $optIn,
            $this->createStub(ContaoFramework::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
        );

        $user = $this->createStub(FrontendUser::class);
        $user->method('__get')->willReturnMap([
            ['email', 'member@example.com'],
            ['id', 7],
        ]);

        self::assertSame(
            $submitted,
            $listener->onSaveEmail($submitted, $user, $this->createStub(ModulePersonalData::class)),
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
}
