<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\OptIn;

use Contao\OptInModel;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\OptIn\UnconfirmedTokenPurger;

/**
 * Unlike PurgeExpiredEmailChangeAnchorsCron, this class never touches a Connection
 * directly - every read goes through OptInModel::findUnconfirmedByRelatedTableAndId(),
 * a legacy Model method backed by Contao's Database/Registry singletons rather than an
 * injected DBAL connection. Wiring that to a real SQLite schema would mean bootstrapping
 * Contao's own Database class, which no test in this suite does; the adapter is mocked
 * instead, at the same seam the class itself is written against. The core method's own
 * SQL (confirmedOn=0 AND id IN (SELECT pid FROM tl_opt_in_related WHERE relTable=?
 * AND relId=?)) already scopes the result to ONE member's UNCONFIRMED tokens - that is
 * why a confirmed token, or another member's token, never even reaches purge()/purgeAll()
 * and is represented here by the adapter simply not returning it.
 */
class UnconfirmedTokenPurgerTest extends ContaoTestCase
{
    public function testPurgeDeletesOnlyTheTokensWithTheGivenPrefix(): void
    {
        $emailToken = $this->tokenModel('email-abc123');
        $emailToken->expects(self::once())->method('delete');

        $pwToken = $this->tokenModel('pw-xyz789');
        $pwToken->expects(self::never())->method('delete');

        $adapter = $this->createConfiguredAdapterMock(['findUnconfirmedByRelatedTableAndId' => [$emailToken, $pwToken]]);
        $framework = $this->createContaoFrameworkMock([OptInModel::class => $adapter]);

        $adapter->expects(self::once())->method('findUnconfirmedByRelatedTableAndId')->with('tl_member', 7);

        (new UnconfirmedTokenPurger($framework))->purge(7, 'email');
    }

    public function testPurgeAllDeletesEveryUnconfirmedTokenRegardlessOfPrefix(): void
    {
        $emailToken = $this->tokenModel('email-abc123');
        $emailToken->expects(self::once())->method('delete');

        $mdaccToken = $this->tokenModel('mdacc-def456');
        $mdaccToken->expects(self::once())->method('delete');

        $adapter = $this->createConfiguredAdapterMock(['findUnconfirmedByRelatedTableAndId' => [$emailToken, $mdaccToken]]);
        $framework = $this->createContaoFrameworkMock([OptInModel::class => $adapter]);

        $adapter->expects(self::once())->method('findUnconfirmedByRelatedTableAndId')->with('tl_member', 8);

        (new UnconfirmedTokenPurger($framework))->purgeAll(8);
    }

    /**
     * A member whose only token is already confirmed: the core query never returns it
     * (confirmedOn=0 excludes it) and answers null - purge() must not choke on that.
     */
    public function testPurgeIsANoopWhenTheMemberHasNoUnconfirmedToken(): void
    {
        $adapter = $this->createConfiguredAdapterMock(['findUnconfirmedByRelatedTableAndId' => null]);
        $framework = $this->createContaoFrameworkMock([OptInModel::class => $adapter]);

        (new UnconfirmedTokenPurger($framework))->purge(9, 'email');

        $this->addToAssertionCount(1); // reaching here without a TypeError/exception is the point
    }

    private function tokenModel(string $token): OptInModel&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createClassWithPropertiesMock(OptInModel::class, ['token' => $token]);
    }
}
