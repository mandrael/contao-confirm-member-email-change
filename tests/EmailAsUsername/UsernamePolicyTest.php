<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailAsUsername;

use Contao\MemberModel;
use Contao\TestCase\ContaoTestCase;
use Contao\Validator;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EligibilityReason;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;

class UsernamePolicyTest extends ContaoTestCase
{
    public function testRejectsAnEmptyEmail(): void
    {
        self::assertSame(EligibilityReason::EmailEmpty, $this->policy()->evaluate('  '));
    }

    public function testRejectsAnAddressLongerThan64Characters(): void
    {
        $email = str_repeat('a', 60).'@example.com';
        self::assertGreaterThan(64, mb_strlen($email));

        self::assertSame(EligibilityReason::TooLong, $this->policy()->evaluate($email));
    }

    public function testRejectsAnAddressFailingTheExtndCheck(): void
    {
        $validator = $this->createConfiguredAdapterMock(['isExtendedAlphanumeric' => false]);
        $policy = $this->policy($validator);

        self::assertSame(EligibilityReason::Invalid, $policy->evaluate('a#b@example.com'));
    }

    public function testRejectsAUsernameAlreadyTakenByAnotherMember(): void
    {
        $validator = $this->createConfiguredAdapterMock(['isExtendedAlphanumeric' => true]);
        $memberAdapter = $this->createConfiguredAdapterMock(['findOneBy' => $this->createMock(MemberModel::class)]);
        $policy = $this->policy($validator, $memberAdapter);

        self::assertSame(EligibilityReason::Collision, $policy->evaluate('member@example.com', 7));
    }

    public function testIsEligibleWhenNothingConflicts(): void
    {
        $validator = $this->createConfiguredAdapterMock(['isExtendedAlphanumeric' => true]);
        $memberAdapter = $this->createConfiguredAdapterMock(['findOneBy' => null]);
        $policy = $this->policy($validator, $memberAdapter);

        self::assertSame(EligibilityReason::Eligible, $policy->evaluate('Member@Example.com', 7));
    }

    private function policy(?object $validatorAdapter = null, ?object $memberAdapter = null): UsernamePolicy
    {
        $framework = $this->createContaoFrameworkMock([
            Validator::class => $validatorAdapter ?? $this->createConfiguredAdapterMock(['isExtendedAlphanumeric' => true]),
            MemberModel::class => $memberAdapter ?? $this->createConfiguredAdapterMock(['findOneBy' => null]),
        ]);

        return new UsernamePolicy($framework);
    }
}
