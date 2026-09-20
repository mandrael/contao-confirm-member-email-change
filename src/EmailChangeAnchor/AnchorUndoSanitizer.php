<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\EmailChangeAnchor;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * eval.doNotCopy keeps the five anchor fields off
 * a COPIED member, but DC_Table::delete() has no such flag - it snapshots the whole row
 * (including a still-pending plaintext token in emailChangeAnchorPending) into
 * tl_undo.data BEFORE this callback runs, and undo() later re-INSERTs that snapshot
 * verbatim, column for column (core 5.3/5.7 DC_Table.php, delete()/undo()). A member who
 * gets deleted and restored must not come back with a working anchor, so this strips the
 * five fields from the just-written tl_undo row entirely - a column absent from the
 * INSERT falls back to its SQL default on undo(), exactly like a copy already does.
 */
final class AnchorUndoSanitizer
{
    private const ANCHOR_FIELDS = [
        'emailChangeAnchorHash',
        'emailChangeAnchorEmail',
        'emailChangeAnchorExpires',
        'emailChangeAnchorNotified',
        'emailChangeAnchorPending',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[AsCallback(table: 'tl_member', target: 'config.ondelete_callback')]
    public function onDeleteMember(DataContainer $dc, int $undoId): void
    {
        $row = $this->connection->fetchAssociative('SELECT data FROM tl_undo WHERE id = ?', [$undoId]);

        if (false === $row) {
            return;
        }

        $data = unserialize((string) $row['data'], ['allowed_classes' => false]);

        if (!\is_array($data) || !isset($data['tl_member']) || !\is_array($data['tl_member'])) {
            return;
        }

        foreach ($data['tl_member'] as &$memberRow) {
            if (!\is_array($memberRow)) {
                continue;
            }

            foreach (self::ANCHOR_FIELDS as $field) {
                unset($memberRow[$field]);
            }
        }

        unset($memberRow);

        $this->connection->executeStatement('UPDATE tl_undo SET data = ? WHERE id = ?', [serialize($data), $undoId]);
    }
}
