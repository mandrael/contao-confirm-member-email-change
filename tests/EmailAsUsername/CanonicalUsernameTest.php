<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\CanonicalUsername;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalUsernameTest extends TestCase
{
    #[DataProvider('emails')]
    public function testNormalizesToLowercasedTrimmedEmail(string $email, string $expected): void
    {
        self::assertSame($expected, CanonicalUsername::normalize($email));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function emails(): array
    {
        return [
            'already lowercase' => ['member@example.com', 'member@example.com'],
            'mixed case' => ['Member@Example.com', 'member@example.com'],
            'surrounding whitespace' => ['  Member@Example.com  ', 'member@example.com'],
        ];
    }
}
