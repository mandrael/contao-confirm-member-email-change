<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Command;

use Contao\MemberModel;
use Contao\StringUtil;
use Contao\TestCase\ContaoTestCase;
use Contao\Validator;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Command\SyncUsernamesCommand;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Symfony\Component\Console\Tester\CommandTester;

class SyncUsernamesCommandTest extends ContaoTestCase
{
    public function testDryRunReportsCasesAndDoesNotWrite(): void
    {
        $eligible = $this->member(1, 'new@example.com', '', 'a:0:{}');
        $eligible->expects(self::never())->method('save');

        $unchanged = $this->member(2, 'same@example.com', 'same@example.com', 'a:0:{}');
        $unchanged->expects(self::never())->method('save');

        $tester = $this->tester([$eligible, $unchanged]);
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('würde umgestellt: 1', $output);
        self::assertStringContainsString('unverändert: 1', $output);
        self::assertStringNotContainsString('new@example.com', $output);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function testForceWritesTheEligibleChange(): void
    {
        $eligible = $this->member(1, 'New@Example.com', '', 'a:0:{}');
        $eligible->expects(self::once())->method('save');

        $tester = $this->tester([$eligible]);
        $tester->execute(['--force' => true]);

        self::assertSame('new@example.com', $eligible->username);
        self::assertStringContainsString('umgestellt: 1', $tester->getDisplay());
    }

    public function testGroupOptionLimitsToMatchingMembers(): void
    {
        $inGroup = $this->member(1, 'in@example.com', '', 'a:1:{i:0;i:2;}');
        $inGroup->expects(self::never())->method('save');

        $outOfGroup = $this->member(2, 'out@example.com', '', 'a:1:{i:0;i:9;}');
        $outOfGroup->expects(self::never())->method('save');

        $tester = $this->tester([$inGroup, $outOfGroup]);
        $tester->execute(['--group' => '2']);

        // Only the matching member (ID 1) may be counted – proves the group filter, not just
        // that both were reported, since a leaked out-of-group member would show a count of 2.
        self::assertStringContainsString('würde umgestellt: 1', $tester->getDisplay());
    }

    /**
     * @param list<MemberModel&\PHPUnit\Framework\MockObject\MockObject> $members
     */
    private function tester(array $members): CommandTester
    {
        $memberAdapter = $this->createAdapterMock(['findAll', 'findOneBy']);
        $memberAdapter->method('findAll')->willReturn($members);
        $memberAdapter->method('findOneBy')->willReturn(null); // No username collisions in these fixtures.

        $stringUtilAdapter = $this->createAdapterMock(['deserialize']);
        $stringUtilAdapter->method('deserialize')->willReturnCallback(
            static fn (string $value): array => unserialize($value, ['allowed_classes' => false]),
        );

        $validatorAdapter = $this->createConfiguredAdapterMock(['isExtendedAlphanumeric' => true]);

        $framework = $this->createContaoFrameworkMock([
            MemberModel::class => $memberAdapter,
            StringUtil::class => $stringUtilAdapter,
            Validator::class => $validatorAdapter,
        ]);

        $command = new SyncUsernamesCommand($framework, new UsernamePolicy($framework));

        return new CommandTester($command);
    }

    private function member(int $id, string $email, string $username, string $groups): MemberModel&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createClassWithPropertiesMock(MemberModel::class, [
            'id' => $id,
            'email' => $email,
            'username' => $username,
            'groups' => $groups,
        ]);
    }
}
