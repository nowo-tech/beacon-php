<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Context;

use Nowo\Beacon\Context\DatabaseExceptionContext;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class DatabaseExceptionContextTest extends TestCase
{
    public function testExtractsSqlstateAndSqlFromLaravelStyleMessage(): void
    {
        $sql     = "select `id` from `attendances` where `email` = 'secret@example.test' group by `date`";
        $message = 'SQLSTATE[42000]: Syntax error or access violation: 1055 Expression #1; sql_mode=only_full_group_by (Connection: mysql, SQL: ' . $sql . ')';
        $ctx     = DatabaseExceptionContext::fromThrowable(new RuntimeException($message));

        self::assertIsArray($ctx);
        self::assertSame('sql', $ctx['type']);
        self::assertSame('42000', $ctx['sqlstate']);
        self::assertSame('1055', $ctx['code']);
        self::assertSame('mysql', $ctx['driver']);
        self::assertSame('only_full_group_by', $ctx['sql_mode']);
        self::assertIsString($ctx['sql']);
        self::assertStringContainsString('attendances', $ctx['sql']);
        self::assertStringNotContainsString('secret@example.test', $ctx['sql']);
        self::assertStringContainsString("'?'", $ctx['sql']);
    }

    public function testUsesPdoErrorInfo(): void
    {
        $pdo            = new PDOException('SQLSTATE[HY000] [1040] Too many connections');
        $pdo->errorInfo = ['HY000', 1040, 'Too many connections'];

        $ctx = DatabaseExceptionContext::fromThrowable($pdo);
        self::assertIsArray($ctx);
        self::assertSame('HY000', $ctx['sqlstate']);
        self::assertSame('1040', $ctx['code']);
        self::assertSame('pdo', $ctx['driver']);
    }

    public function testReturnsNullForNonDatabaseThrowable(): void
    {
        self::assertNull(DatabaseExceptionContext::fromThrowable(new RuntimeException('plain boom')));
    }

    public function testDuckTypedDbalQueryWinsForSql(): void
    {
        $inner = new class('SQLSTATE[23505]: Unique violation') extends RuntimeException {
            public function getSQLState(): string
            {
                return '23505';
            }

            public function getQuery(): object
            {
                return new class {
                    public function getSQL(): string
                    {
                        return "INSERT INTO users (email) VALUES ('a@b.c')";
                    }
                };
            }
        };

        $ctx = DatabaseExceptionContext::fromThrowable(new RuntimeException('wrapper', 0, $inner));
        self::assertIsArray($ctx);
        self::assertSame('23505', $ctx['sqlstate']);
        self::assertIsString($ctx['sql']);
        self::assertStringContainsString('INSERT INTO users', $ctx['sql']);
        self::assertStringNotContainsString('a@b.c', $ctx['sql']);
    }

    public function testDuckTypedGetQueryString(): void
    {
        $ex = new class('SQLSTATE[42S02]: Base table or view not found') extends RuntimeException {
            public function getSQLState(): string
            {
                return '42S02';
            }

            public function getQuery(): string
            {
                return "SELECT * FROM missing WHERE email = 'x@y.z'";
            }
        };

        $ctx = DatabaseExceptionContext::fromThrowable($ex);
        self::assertIsArray($ctx);
        self::assertSame('42S02', $ctx['sqlstate']);
        self::assertStringContainsString('SELECT * FROM missing', $ctx['sql']);
        self::assertStringNotContainsString('x@y.z', $ctx['sql']);
    }

    public function testParsesMysqlStyleParenCodeAndDropsDriverOnly(): void
    {
        $ctx = DatabaseExceptionContext::fromThrowable(new RuntimeException('Error (1040, "Too many connections")'));
        self::assertIsArray($ctx);
        self::assertSame('1040', $ctx['code']);

        self::assertNull(DatabaseExceptionContext::fromThrowable(new RuntimeException('Connection: mysql')));
    }

    public function testMergeSkipsEmptyOverlayValues(): void
    {
        $method = new ReflectionMethod(DatabaseExceptionContext::class, 'merge');
        $merged = $method->invoke(null, ['sqlstate' => 'HY000'], ['sqlstate' => '', 'code' => '1040', 'driver' => null]);
        self::assertSame(['sqlstate' => 'HY000', 'code' => '1040'], $merged);
    }

    public function testScrubSqlTruncates(): void
    {
        $sql = DatabaseExceptionContext::scrubSql(str_repeat('SELECT 1, ', 2000));
        self::assertSame(DatabaseExceptionContext::MAX_SQL_LENGTH, mb_strlen($sql));
    }
}
