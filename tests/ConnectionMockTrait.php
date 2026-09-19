<?php

declare(strict_types=1);

namespace Mandrael\ContaoConfirmMemberEmailChangeBundle\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Both controllers read and write through DBAL under a row lock, so their tests need a
 * connection that answers per statement and records what happened in which ORDER - the
 * order is exactly what the concurrency protocol is about.
 */
trait ConnectionMockTrait
{
    /**
     * Everything the connection saw, in order: "begin", "read:<sql>", "write:<sql>",
     * "commit", "rollBack".
     *
     * @var list<string>
     */
    private array $log = [];

    /**
     * @var list<array{sql: string, params: array<mixed>}>
     */
    private array $statements = [];

    /**
     * @param array<string, array<string, mixed>|false>   $rows    fetchAssociative: SQL substring => row or false
     * @param array<string, mixed>                        $scalars fetchOne: SQL substring => value
     * @param array<string, list<array<string, mixed>>>   $lists   fetchAllAssociative: SQL substring => list of rows
     */
    private function createConnectionMock(array $rows = [], array $scalars = [], array $lists = []): Connection&MockObject
    {
        $this->log = [];
        $this->statements = [];

        $connection = $this->createMock(Connection::class);

        $pick = static function (string $sql, array $map, mixed $default): mixed {
            foreach ($map as $needle => $value) {
                if (str_contains($sql, $needle)) {
                    return $value;
                }
            }

            return $default;
        };

        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql) use ($pick, $rows): array|false {
                $this->log[] = 'read:'.$sql;

                return $pick($sql, $rows, false);
            },
        );

        $connection->method('fetchOne')->willReturnCallback(
            function (string $sql) use ($pick, $scalars): mixed {
                $this->log[] = 'read:'.$sql;

                return $pick($sql, $scalars, false);
            },
        );

        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use ($pick, $lists): array {
                $this->log[] = 'read:'.$sql;

                return $pick($sql, $lists, []);
            },
        );

        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->log[] = 'write:'.$sql;
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        $connection->method('beginTransaction')->willReturnCallback(
            function (): void {
                $this->log[] = 'begin';
            },
        );

        $connection->method('commit')->willReturnCallback(
            function (): void {
                $this->log[] = 'commit';
            },
        );

        $connection->method('rollBack')->willReturnCallback(
            function (): void {
                $this->log[] = 'rollBack';
            },
        );

        $connection->method('isTransactionActive')->willReturnCallback(
            fn (): bool => \in_array('begin', $this->log, true) && !\in_array('commit', $this->log, true) && !\in_array('rollBack', $this->log, true),
        );

        return $connection;
    }

    /**
     * @return list<array{sql: string, params: array<mixed>}>
     */
    private function statementsContaining(string $needle): array
    {
        return array_values(array_filter($this->statements, static fn (array $s): bool => str_contains($s['sql'], $needle)));
    }
}
