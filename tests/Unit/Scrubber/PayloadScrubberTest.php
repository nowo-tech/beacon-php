<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use Nowo\Beacon\Scrubber\PayloadScrubber;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

final class PayloadScrubberTest extends TestCase
{
    public function testScrubsEveryContainerBeforeBeforeSend(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < PayloadScrubber::MAX_DEPTH + 2; ++$i) {
            $deep = ['level' => $deep];
        }

        $payload = (new PayloadScrubber())->scrub([
            'message'   => 'card 4111 1111 1111 1111 refused, password=hunter2',
            'exception' => ['values' => [['type' => 'E', 'value' => 'pan 4111111111111111'], 'not-an-array', ['type' => 'F']]],
            'extra'     => [
                'session_id'  => 'abc',
                'csrf'        => 'x',
                'user_pass'   => 'p',
                'passport'    => 'kept',
                'card_number' => '4111111111111111',
                'amount'      => 4111111111111111,
                'small'       => 42,
                'ratio'       => 0.5,
                'flag'        => true,
                'none'        => null,
                'obj'         => new stdClass(),
                'url'         => 'https://user:pw@db.internal/x',
                'list'        => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c'],
                'deep'        => $deep,
            ],
            'contexts' => ['app' => ['api_key' => 'k', 'name' => 'shop']],
            'tags'     => ['iban' => 'ES00', 'area' => 'billing'],
            'request'  => [
                'url'          => 'https://shop.example/a?token=t&x=1',
                'query_string' => 'token=t&x=1',
                'headers'      => ['Authorization' => 'Bearer abcdefghijkl', 'X-Custom-Token' => 'v', 'Accept' => 'text/html'],
                'cookies'      => 'session=abc',
                'data'         => ['password' => 'p', 'name' => 'n'],
            ],
            'breadcrumbs' => ['values' => [
                ['message' => 'login password=x', 'data' => ['url' => 'https://a.example/?code=123', 'secret' => 's', 'to' => 'https://b.example/?sig=1', 'n' => 1]],
                'skip',
                ['message' => 'no data'],
            ]],
            'user' => ['email' => 'jane@example.com'],
        ]);

        self::assertSame('card [Filtered] refused, password=[Filtered]', $payload['message']);
        self::assertSame('pan [Filtered]', $payload['exception']['values'][0]['value']);
        self::assertSame('not-an-array', $payload['exception']['values'][1]);
        $extra = $payload['extra'];
        foreach (['session_id', 'csrf', 'user_pass', 'card_number', 'amount'] as $key) {
            self::assertSame('[Filtered]', $extra[$key], $key);
        }
        self::assertSame('kept', $extra['passport']);
        self::assertSame(42, $extra['small']);
        self::assertSame(0.5, $extra['ratio']);
        self::assertTrue($extra['flag']);
        self::assertNull($extra['none']);
        self::assertSame(stdClass::class, $extra['obj']);
        self::assertSame('https://[Filtered]@db.internal/x', $extra['url']);
        self::assertSame(['[Filtered]'], $extra['list']);
        self::assertStringContainsString('[Filtered]', (string) json_encode($extra['deep']));
        self::assertSame(['app' => ['api_key' => '[Filtered]', 'name' => 'shop']], $payload['contexts']);
        self::assertSame(['iban' => '[Filtered]', 'area' => 'billing'], $payload['tags']);
        self::assertSame('https://shop.example/a?token=[Filtered]&x=1', $payload['request']['url']);
        self::assertSame('token=[Filtered]&x=1', $payload['request']['query_string']);
        self::assertSame(['Authorization' => '[Filtered]', 'X-Custom-Token' => '[Filtered]', 'Accept' => 'text/html'], $payload['request']['headers']);
        self::assertSame('[Filtered]', $payload['request']['cookies']);
        self::assertSame(['password' => '[Filtered]', 'name' => 'n'], $payload['request']['data']);
        $crumb = $payload['breadcrumbs']['values'][0];
        self::assertSame('login password=[Filtered]', $crumb['message']);
        self::assertSame('https://a.example/?code=[Filtered]', $crumb['data']['url']);
        self::assertSame('https://b.example/?sig=[Filtered]', $crumb['data']['to']);
        self::assertSame('[Filtered]', $crumb['data']['secret']);
        self::assertSame(1, $crumb['data']['n']);
        self::assertSame('skip', $payload['breadcrumbs']['values'][1]);
        self::assertSame(['email' => 'jane@example.com'], $payload['user']);
    }

    public function testKeepsNonCardNumbersAndShortText(): void
    {
        self::assertSame('order 1234567890123 ok', PayloadScrubber::maskCards('order 1234567890123 ok'));
        self::assertSame('short', PayloadScrubber::maskCards('short'));
        self::assertSame(str_repeat('a', 20), PayloadScrubber::scrubText(str_repeat('a', 20)));
        self::assertTrue(PayloadScrubber::isSensitiveKey('X-Session'));
        self::assertFalse(PayloadScrubber::isSensitiveKey('compass'));
        self::assertSame([], (new PayloadScrubber())->scrub([]));
    }
}
