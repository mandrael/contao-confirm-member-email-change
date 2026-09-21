<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\EmailChangeAnchor;

use Doctrine\DBAL\Connection;
use Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor\AnchorUndoSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * DC_Table::delete() snapshots the whole tl_member row into tl_undo.data BEFORE this
 * callback runs, so a restored member must not come back with a working anchor - the
 * five anchor fields have to be stripped from that snapshot, and nothing else touched.
 */
class AnchorUndoSanitizerTest extends TestCase
{
    public function testStripsOnlyTheFiveAnchorFieldsFromTheMemberRowAndLeavesEverythingElseUntouched(): void
    {
        $data = [
            'tl_member' => [
                [
                    'id' => 7,
                    'email' => 'member@example.com',
                    'emailChangeAnchorHash' => str_repeat('a', 64),
                    'emailChangeAnchorEmail' => 'old@example.com',
                    'emailChangeAnchorExpires' => 123456,
                    'emailChangeAnchorNotified' => 1,
                    'emailChangeAnchorPending' => 'plaintext-token',
                ],
            ],
            // A different table in the same tl_undo snapshot must survive unchanged.
            'tl_member_location' => [
                ['id' => 1, 'pid' => 7, 'city' => 'Vienna'],
            ],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->with('SELECT data FROM tl_undo WHERE id = ?', [99])
            ->willReturn(['data' => serialize($data)])
        ;

        $written = null;
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$written): int {
                self::assertSame('UPDATE tl_undo SET data = ? WHERE id = ?', $sql);
                self::assertSame(99, $params[1]);
                $written = unserialize((string) $params[0], ['allowed_classes' => false]);

                return 1;
            },
        );

        (new AnchorUndoSanitizer($connection))->onDeleteMember($this->createStub(\Contao\DataContainer::class), 99);

        self::assertNotNull($written);
        $memberRow = $written['tl_member'][0];

        foreach (['emailChangeAnchorHash', 'emailChangeAnchorEmail', 'emailChangeAnchorExpires', 'emailChangeAnchorNotified', 'emailChangeAnchorPending'] as $field) {
            self::assertArrayNotHasKey($field, $memberRow, \sprintf('%s must be stripped from the undo snapshot', $field));
        }

        self::assertSame(7, $memberRow['id']);
        self::assertSame('member@example.com', $memberRow['email']);
        self::assertSame($data['tl_member_location'], $written['tl_member_location'], 'a foreign table in the same snapshot must survive unchanged');
    }
}
