<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailChangeAnchor;

use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\EmailChangeAnchorPolicy;
use PHPUnit\Framework\TestCase;

class EmailChangeAnchorPolicyTest extends TestCase
{
    public function testTtlDaysMatchesTheConstantInDays(): void
    {
        self::assertSame(14, EmailChangeAnchorPolicy::ttlDays());
        self::assertSame(14 * 86400, EmailChangeAnchorPolicy::TTL_SECONDS);
    }

    public function testHasValidAnchorRequiresBothAHashAndAFutureExpiry(): void
    {
        $now = 1_000_000;

        self::assertTrue(EmailChangeAnchorPolicy::hasValidAnchor(str_repeat('a', 64), $now + 1, $now));
        self::assertFalse(EmailChangeAnchorPolicy::hasValidAnchor('', $now + 1, $now), 'empty hash = no anchor');
        self::assertFalse(EmailChangeAnchorPolicy::hasValidAnchor(str_repeat('a', 64), $now, $now), 'expiresAt == now is no longer valid');
        self::assertFalse(EmailChangeAnchorPolicy::hasValidAnchor(str_repeat('a', 64), $now - 1, $now), 'expired');
    }

    public function testMatchesTokenComparesTheHashOfTheProvidedToken(): void
    {
        $token = 'a-real-token';
        $hash = EmailChangeAnchorPolicy::hashToken($token);

        self::assertTrue(EmailChangeAnchorPolicy::matchesToken($hash, $token));
        self::assertFalse(EmailChangeAnchorPolicy::matchesToken($hash, 'a-wrong-token'));
        self::assertFalse(EmailChangeAnchorPolicy::matchesToken('', $token), 'no stored hash never matches');
    }

    public function testHashTokenIsDeterministicSha256(): void
    {
        self::assertSame(hash('sha256', 'x'), EmailChangeAnchorPolicy::hashToken('x'));
        self::assertNotSame(EmailChangeAnchorPolicy::hashToken('a'), EmailChangeAnchorPolicy::hashToken('b'));
    }
}
