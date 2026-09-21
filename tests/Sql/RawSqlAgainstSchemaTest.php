<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests\Sql;

use PHPUnit\Framework\TestCase;

/**
 * Review Runde 3, Pflicht 1 ("Rot-Beleg"): every raw SQL statement of this bundle that
 * touches tl_opt_in, tl_opt_in_related, tl_member or tl_undo runs against an in-memory
 * SQLite schema built from a FIXED column list - not a PHPUnit mock, which happily
 * returns whatever key a test tells it to (that is exactly how the Runde-2 regression,
 * a SELECT of the non-existent tl_opt_in.relatedRecords column, passed every unit test
 * while failing on every real MariaDB). Run this test against the pre-fix source (the
 * old query selecting "relatedRecords" from tl_opt_in) and it goes RED with "Unknown
 * column" - that is the point of it.
 *
 * SQLite has no FOR UPDATE / BINARY syntax, so those MySQL-only tokens are stripped
 * before executing - this test proves the COLUMN NAMES are real, not full MySQL dialect
 * compatibility (that is what the manual ROLLBACK run against the real DDEV/MariaDB
 * installation is for).
 */
final class RawSqlAgainstSchemaTest extends TestCase
{
    /**
     * Contao core DCA for the first two (contao/core-bundle/contao/dca/tl_opt_in.php,
     * tl_opt_in_related.php - identical in 5.3 and 5.7). tl_member is trimmed to the
     * core columns this bundle actually reads/writes plus its own five anchor fields
     * (contao/dca/tl_member.php), not the full ~80-column core table. tl_undo per
     * DC_Table::delete()'s own INSERT (core drivers/DC_Table.php).
     *
     * @var array<string, list<string>>
     */
    private const SCHEMA = [
        'tl_opt_in' => ['id', 'tstamp', 'token', 'createdOn', 'confirmedOn', 'removeOn', 'invalidatedThrough', 'email', 'emailSubject', 'emailText'],
        'tl_opt_in_related' => ['id', 'pid', 'relTable', 'relId'],
        'tl_member' => [
            'id', 'tstamp', 'email', 'username', 'password', 'login',
            'emailChangeAnchorHash', 'emailChangeAnchorEmail', 'emailChangeAnchorExpires',
            'emailChangeAnchorNotified', 'emailChangeAnchorPending',
        ],
        'tl_undo' => ['id', 'pid', 'tstamp', 'fromTable', 'query', 'affectedRows', 'data'],
        // AnchorNotice::applySender() root-page administrator-address fallback.
        'tl_page' => ['id', 'type', 'adminEmail', 'sorting'],
    ];

    /**
     * Runde-4 addition: AnchorNotice::applySender() reads tl_page.adminEmail when
     * neither the page context nor the global setting has an address. Before "tl_page"
     * was added to SCHEMA above, the extractor's own table regex did not match that
     * statement at all - it silently went unchecked rather than failing loudly. This
     * pins the extractor actually picking it up, not just the schema tolerating it.
     */
    public function testTheRootPageFallbackQueryIsCoveredByTheExtractor(): void
    {
        $statements = $this->collectStatements();
        $pageStatements = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'tl_page'));

        self::assertCount(1, $pageStatements, 'exactly one raw SQL statement should touch tl_page');
        self::assertStringContainsString('adminEmail', reset($pageStatements));
    }

    public function testEveryRawSqlStatementMatchesTheSchema(): void
    {
        $statements = $this->collectStatements();

        self::assertNotEmpty($statements, 'extraction found no SQL at all - the regex broke, not a good sign either way');

        $pdo = $this->buildSchema();
        $errors = [];

        foreach ($statements as $where => $sql) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare($this->forSqlite($sql));
                $stmt->execute(array_fill(0, substr_count($sql, '?'), 1));
                $pdo->rollBack();
            } catch (\PDOException $e) {
                $pdo->rollBack();
                $errors[] = \sprintf('%s: %s -- %s', $where, $e->getMessage(), $sql);
            }
        }

        self::assertSame(
            [],
            $errors,
            \sprintf("%d von %d SQL-Anweisungen fehlerfrei:\n%s", \count($statements) - \count($errors), \count($statements), implode("\n", $errors)),
        );
    }

    /**
     * @return array<string, string> "file:line" => SQL
     */
    private function collectStatements(): array
    {
        $dir = \dirname(__DIR__, 2).'/src';
        $statements = [];
        $tables = implode('|', array_keys(self::SCHEMA));

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            foreach (file((string) $file->getPathname()) as $lineNo => $line) {
                // Whole PHP string literals, quote-aware so an embedded '' inside a
                // double-quoted SQL string (e.g. "... != ''") does not truncate the match.
                preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', $line, $literals);

                foreach ($literals[0] as $literal) {
                    $sql = substr($literal, 1, -1);

                    if (preg_match('/^(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql) && preg_match('/\b(?:'.$tables.')\b/', $sql)) {
                        $statements[$file->getFilename().':'.($lineNo + 1)] = $sql;
                    }
                }
            }
        }

        return $statements;
    }

    /**
     * MySQL-only tokens this bundle uses that SQLite does not understand - stripped so
     * the check stays scoped to column names, see the class doc comment.
     */
    private function forSqlite(string $sql): string
    {
        $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql);

        return preg_replace('/\bBINARY\s+/i', '', (string) $sql);
    }

    private function buildSchema(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach (self::SCHEMA as $table => $columns) {
            $pdo->exec(\sprintf('CREATE TABLE %s (%s)', $table, implode(', ', $columns)));
        }

        return $pdo;
    }
}
