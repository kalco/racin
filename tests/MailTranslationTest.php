<?php

declare(strict_types=1);

namespace Racin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Racin\Broadcasting\ChannelRegistry;
use Racin\Cache\Cache;
use Racin\Cache\RedisStore;
use Racin\Mail\ArrayTransport;
use Racin\Mail\Mailer;
use Racin\Mail\Message;
use Racin\Mail\SmtpTransport;
use Racin\Mail\TransportException;
use Racin\Translation\Translator;

final class MailTranslationTest extends TestCase
{
    private function message(): Message
    {
        return (new Message())
            ->from('shop@example.com', 'Роќа Shop')
            ->to('ana@example.com', 'Ana "A" P.')
            ->bcc('audit@example.com')
            ->subject('Нарачка ORD-1 е примена')
            ->text("Line one\n.starts with a dot\nLine three")
            ->html('<p>Здраво <b>Ana</b></p>')
            ->attachData('col1,col2', 'report.csv', 'text/csv');
    }

    public function testMimeStructureAndHeaderEncoding(): void
    {
        $mime = $this->message()->toMime('example.com');
        [$headers, $body] = explode("\r\n\r\n", $mime, 2);

        self::assertStringContainsString('From: =?UTF-8?B?' . base64_encode('Роќа Shop') . '?= <shop@example.com>', $headers);
        self::assertStringContainsString('To: "Ana \"A\" P." <ana@example.com>', $headers);
        self::assertStringNotContainsString('audit@example.com', $mime, 'Bcc is not a header');
        self::assertStringContainsString('Subject: =?UTF-8?B?', $headers);
        self::assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{24}@example\.com>/', $headers);
        self::assertStringContainsString('Content-Type: multipart/mixed;', $headers);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $body);
        self::assertStringContainsString('Content-Disposition: attachment; filename="report.csv"', $body);
        self::assertStringContainsString(base64_encode('col1,col2'), $body);
        self::assertSame(['ana@example.com', 'audit@example.com'], $this->message()->recipients());
        self::assertStringNotContainsString("\r\r", $mime);
    }

    public function testHeaderInjectionIsBlocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Message())->to("victim@example.com\r\nBcc: spam@example.com");
    }

    public function testMessageSurvivesTheQueueRoundTrip(): void
    {
        $copy = Message::fromArray(json_decode((string) json_encode($this->message()->toArray()), true));

        self::assertSame($this->message()->recipients(), $copy->recipients());
        self::assertSame('Нарачка ORD-1 е примена', $copy->getSubject());
        self::assertStringContainsString(base64_encode('col1,col2'), $copy->toMime());
    }

    public function testMailerFillsTheDefaultSender(): void
    {
        $transport = new ArrayTransport();
        (new Mailer($transport, new \Racin\Mail\Address('noreply@example.com', 'App')))->send((new Message())->to('a@b.co')->subject('Hi')->text('x'));

        self::assertSame('noreply@example.com', $transport->messages()[0]->getFrom()?->email);
    }

    public function testSmtpConversation(): void
    {
        [$client, $server] = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        // Server replies, queued up front: the client reads them in order.
        fwrite($server, implode("\r\n", [
            '220 fake.smtp ESMTP',
            '250-fake.smtp hello',
            '250 AUTH LOGIN PLAIN',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 Authenticated',
            '250 Sender OK',
            '250 Recipient OK',
            '250 Recipient OK',
            '354 Send data',
            '250 Queued as 42',
            '221 Bye',
        ]) . "\r\n");

        $transport = new SmtpTransport('fake', 25, null, 'user', 'secret', localDomain: 'app.test', connector: static fn () => $client);
        $transport->send($this->message());

        stream_set_blocking($server, false);
        $sent = (string) stream_get_contents($server);
        fclose($server);

        self::assertStringStartsWith("EHLO app.test\r\nAUTH LOGIN\r\n" . base64_encode('user') . "\r\n" . base64_encode('secret') . "\r\n", $sent);
        self::assertStringContainsString("MAIL FROM:<shop@example.com>\r\nRCPT TO:<ana@example.com>\r\nRCPT TO:<audit@example.com>\r\nDATA\r\n", $sent);
        self::assertStringContainsString("\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testSmtpErrorsBecomeExceptions(): void
    {
        [$client, $server] = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "220 hi\r\n250 hello\r\n550 Sender rejected\r\n");

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('550 Sender rejected');

        (new SmtpTransport('fake', 25, null, connector: static fn () => $client))->send($this->message());
    }

    public function testTranslator(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-lang-' . bin2hex(random_bytes(4));
        mkdir("{$dir}/en", 0777, true);
        mkdir("{$dir}/mk");
        file_put_contents("{$dir}/en/app.php", "<?php return ['hi' => 'Hello, :name!', 'only_en' => 'English only', 'items' => '{0} No items|{1} One item|[2,*] :count items', 'nested' => ['deep' => 'Deep']];");
        file_put_contents("{$dir}/mk/app.php", "<?php return ['hi' => 'Здраво, :name!', 'items' => 'Една ставка|:count ставки'];");

        try {
            $t = new Translator($dir, 'en', 'en', ['en', 'mk']);

            self::assertSame('Hello, Ana!', $t->get('app.hi', ['name' => 'Ana']));
            self::assertSame('Deep', $t->get('app.nested.deep'));
            self::assertSame('app.missing', $t->get('app.missing'));
            self::assertSame('No items', $t->choice('app.items', 0));
            self::assertSame('5 items', $t->choice('app.items', 5));

            $t->setLocale('mk');
            self::assertSame('Здраво, Ана!', $t->get('app.hi', ['name' => 'Ана']));
            self::assertSame('English only', $t->get('app.only_en'), 'falls back to English');
            self::assertSame('3 ставки', $t->choice('app.items', 3));

            $t->setLocale('de');
            self::assertSame('mk', $t->getLocale(), 'unsupported locales are ignored');
            self::assertSame('en', $t->defaultLocale());
            self::assertSame('../evil.x', $t->get('../evil.x'), 'no path traversal in group names');
        } finally {
            array_map('unlink', glob("{$dir}/*/*.php") ?: []);
            rmdir("{$dir}/en");
            rmdir("{$dir}/mk");
            rmdir($dir);
        }
    }

    public function testChannelRegistry(): void
    {
        $channels = new ChannelRegistry();
        $channels->channel('user.{id}', fn (array $user, string $id): bool => $user['id'] === (int) $id);
        $channels->channel('team.{team}.chat', fn (array $user, string $team): bool => in_array($team, $user['teams'], true));

        $user = ['id' => 5, 'teams' => ['red']];

        self::assertTrue($channels->authorize($user, 'user.5'));
        self::assertFalse($channels->authorize($user, 'user.6'));
        self::assertTrue($channels->authorize($user, 'team.red.chat'));
        self::assertFalse($channels->authorize($user, 'team.blue.chat'));
        self::assertTrue($channels->authorize(null, 'public.catalog'));
        self::assertFalse($channels->authorize(null, 'user.5'), 'guests only get public channels');
        self::assertFalse($channels->authorize($user, 'unknown'), 'undefined channels are refused');
        self::assertFalse($channels->authorize($user, "user.5\nx"));
    }

    public function testRedisCacheStore(): void
    {
        $redis = QueueTest::redisOrSkip();
        $prefix = 'racin:test:' . bin2hex(random_bytes(4)) . ':';
        $cache = new Cache(new RedisStore($redis, $prefix));

        try {
            $cache->put('arr', ['a' => 1], 60);
            self::assertSame(['a' => 1], $cache->get('arr'));
            self::assertSame('123', ($cache->put('s', '123') ? $cache->get('s') : null), 'strings stay strings');
            self::assertSame(1, $cache->increment('hits', 1, 60));
            self::assertSame(4, $cache->increment('hits', 3));
            self::assertGreaterThan(0, $redis->ttl($prefix . 'hits'), 'expiry set on creation');
            self::assertFalse($cache->add('arr', 'x'));
            self::assertTrue($cache->add('new', false));
            self::assertFalse($cache->get('new'));

            $other = $redis->set('racin:test:other-app-key', '1');
            $cache->flush();
            self::assertNull($cache->get('arr'));
            self::assertSame('1', $redis->get('racin:test:other-app-key'), 'flush only touches its own prefix');
        } finally {
            $redis->del('racin:test:other-app-key');
            foreach ($redis->keys($prefix . '*') ?: [] as $key) {
                $redis->del($key);
            }
        }
    }
}
