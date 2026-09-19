<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernameFollowRule;
use PHPUnit\Framework\TestCase;

class UsernameFollowRuleTest extends TestCase
{
    public function testFollowsWhenUsernameIsEmpty(): void
    {
        self::assertTrue(UsernameFollowRule::shouldFollow(null, 'old@example.com'));
        self::assertTrue(UsernameFollowRule::shouldFollow('', 'old@example.com'));
    }

    public function testFollowsWhenUsernameMatchesThePreviousEmailCaseInsensitively(): void
    {
        self::assertTrue(UsernameFollowRule::shouldFollow('OLD@example.com', 'old@example.com'));
        self::assertTrue(UsernameFollowRule::shouldFollow('old@example.com', 'OLD@example.com'));
    }

    public function testDoesNotFollowAFantasyUsername(): void
    {
        self::assertFalse(UsernameFollowRule::shouldFollow('johndoe', 'old@example.com'));
    }
}
