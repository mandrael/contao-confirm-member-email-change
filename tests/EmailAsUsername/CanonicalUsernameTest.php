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
            // Contao's own email widgets store an IDN domain as punycode (Idna::encodeEmail()) -
            // the login name has to match that form.
            'unicode domain becomes punycode' => ['Anna@MÜLLER.example', 'anna@xn--mller-kva.example'],
            'already punycode-encoded stays unchanged (idempotent)' => ['anna@xn--mller-kva.example', 'anna@xn--mller-kva.example'],
        ];
    }

    /**
     * Idna::encodeEmail() answers '' for a host it cannot encode (here: an empty label
     * from the double dot) - that must never become the username, the lowercased input
     * stays instead.
     */
    public function testKeepsTheLowercasedInputWhenIdnaCannotEncodeTheHost(): void
    {
        self::assertSame('', \Contao\Idna::encodeEmail('a@a..com'), 'precondition: this host must be one Idna::encodeEmail() cannot encode');
        self::assertSame('a@a..com', CanonicalUsername::normalize('A@A..com'));
    }
}
