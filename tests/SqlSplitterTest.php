<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Database\SqlSplitter;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideQuotesAndComments(): void
    {
        $sql = <<<'SQL'
            -- header comment; with semicolon
            CREATE TABLE `a;b` (id INT);
            # hash comment;
            INSERT INTO t VALUES ('it''s; fine', "dq;", 'back\'slash;');
            /* block; comment */ UPDATE t SET x = 1;
            SELECT '--not a comment'
            SQL;

        self::assertSame([
            'CREATE TABLE `a;b` (id INT)',
            "INSERT INTO t VALUES ('it''s; fine', \"dq;\", 'back\\'slash;')",
            'UPDATE t SET x = 1',
            "SELECT '--not a comment'",
        ], SqlSplitter::split($sql));
    }

    public function testSlug(): void
    {
        self::assertSame('cevapi-kafe-2', \Racin\Support\Str::slug('  Ćevapi & Kafe 2 '));
        self::assertSame('modern-php', \Racin\Support\Str::slug('Modern PHP!'));
        self::assertSame('', \Racin\Support\Str::slug('!!!'));
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], SqlSplitter::split(" -- only a comment\n ; ; "));
    }
}
