<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Command;

use Contao\MemberModel;
use Contao\StringUtil;
use Contao\TestCase\ContaoTestCase;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\Command\SyncUsernamesCommand;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\EmailAsUsernamePolicy;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailAsUsername\UsernamePolicy;
use Symfony\Component\Console\Tester\CommandTester;

class SyncUsernamesCommandTest extends ContaoTestCase
{
    public function testDryRunReportsCasesAndDoesNotWrite(): void
    {
        $eligible = $this->member(1, 'new@example.com', '', 'a:0:{}');
        $unchanged = $this->member(2, 'same@example.com', 'same@example.com', 'a:0:{}');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = $this->tester([$eligible, $unchanged], connection: $connection);
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('würde umgestellt: 1', $output);
        self::assertStringContainsString('unverändert: 1', $output);
        self::assertStringNotContainsString('new@example.com', $output);
        self::assertSame(0, $tester->getStatusCode());
    }

    /**
     * Codex 3 (Runde 2, blockierend): --force writes through a conditional UPDATE, never
     * an unconditional Model::save() - see SyncUsernamesCommand::classify().
     */
    public function testForceWritesTheEligibleChangeWithAConditionalUpdate(): void
    {
        $eligible = $this->member(1, 'New@Example.com', '', 'a:0:{}');

        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        $tester = $this->tester([$eligible], connection: $connection);
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('id = ? AND email = ?', $statements[0][0], 'the write must be conditional on the address just read');
        self::assertSame(['new@example.com', $statements[0][1][1], 1, 'New@Example.com'], $statements[0][1]);
        self::assertStringContainsString('umgestellt: 1', $tester->getDisplay());
    }

    /**
     * Codex 3 (Runde 2, blockierend): zero affected rows - the address changed between
     * findAll() and this write (a racing confirm/revoke) - is reported, not silently
     * counted as a success, and never retried within the same run.
     */
    public function testZeroAffectedRowsIsReportedAsSkipped(): void
    {
        $eligible = $this->member(1, 'New@Example.com', '', 'a:0:{}');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);

        $tester = $this->tester([$eligible], connection: $connection);
        $tester->execute(['--force' => true]);

        self::assertStringNotContainsString('umgestellt: 1', $tester->getDisplay());
        self::assertStringContainsString('übersprungen', $tester->getDisplay());
    }

    public function testGroupOptionLimitsToMatchingMembers(): void
    {
        $inGroup = $this->member(1, 'in@example.com', '', 'a:1:{i:0;i:2;}');
        $outOfGroup = $this->member(2, 'out@example.com', '', 'a:1:{i:0;i:9;}');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = $this->tester([$inGroup, $outOfGroup], connection: $connection);
        $tester->execute(['--group' => '2']);

        // Only the matching member (ID 1) may be counted – proves the group filter, not just
        // that both were reported, since a leaked out-of-group member would show a count of 2.
        self::assertStringContainsString('würde umgestellt: 1', $tester->getDisplay());
    }


    /**
     * DeepSeek BL-2: the command is the migration path FOR the opt-in. With the switch
     * off, --force would silently rewrite every login name with no way back.
     */
    public function testForceIsRefusedWhileTheSwitchIsOff(): void
    {
        $member = $this->member(1, 'New@Example.com', '', 'a:0:{}');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = $this->tester([$member], false, $connection);
        $tester->execute(['--force' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('memberEmailAsUsername', $tester->getDisplay());
    }

    /**
     * The dry run stays available either way, so an operator can see what turning the
     * switch on would do before doing it.
     */
    public function testTheDryRunStillWorksWhileTheSwitchIsOff(): void
    {
        $member = $this->member(1, 'new@example.com', '', 'a:0:{}');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = $this->tester([$member], false, $connection);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('würde umgestellt: 1', $tester->getDisplay());
    }

    /**
     * @param list<MemberModel&\PHPUnit\Framework\MockObject\MockObject> $members
     */
    private function tester(array $members, bool $switchOn = true, Connection|null $connection = null): CommandTester
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

        $policy = $this->createStub(EmailAsUsernamePolicy::class);
        $policy->method('isEnabled')->willReturn($switchOn);

        $command = new SyncUsernamesCommand($framework, new UsernamePolicy($framework), $policy, $connection ?? $this->createStub(Connection::class));

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
