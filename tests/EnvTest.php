<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Config\Config;
use Racin\Config\Env;
use RuntimeException;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::reset();
    }

    public function testParsesQuotesCommentsAndInterpolation(): void
    {
        $values = Env::parse(<<<'ENV'
            # comment
            APP_NAME="My App"
            export HOST=localhost
            PORT=8080 # trailing comment
            URL="http://${HOST}:${PORT}"
            RAW='literal ${HOST} # not a comment'
            EMPTY=
            ESCAPED="line1\nline2 \"quoted\""
            ENV);

        self::assertSame('My App', $values['APP_NAME']);
        self::assertSame('localhost', $values['HOST']);
        self::assertSame('8080', $values['PORT']);
        self::assertSame('http://localhost:8080', $values['URL']);
        self::assertSame('literal ${HOST} # not a comment', $values['RAW']);
        self::assertSame('', $values['EMPTY']);
        self::assertSame("line1\nline2 \"quoted\"", $values['ESCAPED']);
    }

    public function testInvalidLineThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Env::parse("NOT VALID\n");
    }

    public function testGetConvertsSpecialValuesAndRealEnvironmentWins(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "RACIN_T_BOOL=true\nRACIN_T_NULL=null\nRACIN_T_REAL=from-file\n");
        $_ENV['RACIN_T_REAL'] = 'from-env';

        try {
            Env::load($file);

            self::assertTrue(Env::get('RACIN_T_BOOL'));
            self::assertNull(Env::get('RACIN_T_NULL', 'x'));
            self::assertSame('from-env', Env::get('RACIN_T_REAL'));
            self::assertSame('fallback', Env::get('RACIN_T_MISSING', 'fallback'));
        } finally {
            unset($_ENV['RACIN_T_REAL']);
            unlink($file);
        }
    }

    public function testConfigDotNotation(): void
    {
        $config = new Config(['db' => ['connections' => ['mysql' => ['host' => 'h']]]]);
        $config->set('db.connections.mysql.port', 3306);

        self::assertSame('h', $config->get('db.connections.mysql.host'));
        self::assertSame(3306, $config['db.connections.mysql.port']);
        self::assertTrue($config->has('db.connections'));
        self::assertSame('d', $config->get('db.missing', 'd'));
    }
}
