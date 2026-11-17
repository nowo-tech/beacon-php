<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use Nowo\Beacon\Scrubber\PayloadScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function bin2hex;
use function mt_srand;
use function random_bytes;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cardTextProvider(): iterable
    {
        yield 'plain' => ['card 4111111111111111 ok', 'card [Filtered] ok'];
        yield 'spaces' => ['4111 1111 1111 1111', '[Filtered]'];
        yield 'dashes' => ['4111-1111-1111-1111', '[Filtered]'];
        yield 'string start and end' => ['4111111111111111', '[Filtered]'];
        yield 'punctuation' => ['(4111111111111111), "4111111111111111"; [4111111111111111].', '([Filtered]), "[Filtered]"; [[Filtered]].'];
        yield 'colon and equals' => ['pan:4111111111111111 x=4111111111111111', 'pan:[Filtered] x=[Filtered]'];
        yield 'url path' => ['https://shop.example/pay/4111111111111111/confirm', 'https://shop.example/pay/[Filtered]/confirm'];
        yield 'query string' => ['n=1&c=4111111111111111&z=2', 'n=1&c=[Filtered]&z=2'];
        yield 'form data' => ['name=Ann&pan=4111+1111+1111+1111', 'name=Ann&pan=4111+1111+1111+1111'];
        yield 'json' => ['{"pan":"4111111111111111","n":1}', '{"pan":"[Filtered]","n":1}'];
        yield 'json number' => ['{"pan":4111111111111111}', '{"pan":[Filtered]}'];
        yield 'newline' => ["a\n4111 1111 1111 1111\nb", "a\n[Filtered]\nb"];
        yield 'amex' => ['378282246310005', '[Filtered]'];
        yield 'nineteen digits' => ['6304000000000000000', '[Filtered]'];
        yield 'glued suffix' => ['4111111111111111abc', '[Filtered]abc'];
        yield 'glued prefix' => ['abc4111111111111111', 'abc[Filtered]'];
        yield 'glued card word' => ['card4111111111111111', 'card[Filtered]'];
        yield 'glued id=AB' => ['id=AB4111111111111111', 'id=AB[Filtered]'];
        yield 'glued expiry' => ['cc=4111111111111111EXP1225', 'cc=[Filtered]EXP1225'];
        yield 'glued REF and X' => ['REF4012888888881881X', 'REF[Filtered]X'];
        yield 'glued path' => ['/pay/c4111111111111111', '/pay/c[Filtered]'];
        yield 'glued AMEX' => ['AMEX378282246310005', 'AMEX[Filtered]'];
    }

    #[DataProvider('cardTextProvider')]
    public function testMasksCardsAtBoundaries(string $input, string $expected): void
    {
        self::assertSame($expected, PayloadScrubber::maskCards($input));
    }

    public function testCardsInRequestFormData(): void
    {
        $payload = (new PayloadScrubber())->scrub([
            'request' => [
                'data' => ['note' => 'pan 4111-1111-1111-1111 here', 'json' => '{"pan":"4111111111111111"}'],
            ],
        ]);

        self::assertIsArray($payload['request']);
        self::assertIsArray($payload['request']['data']);
        self::assertSame('pan [Filtered] here', $payload['request']['data']['note']);
        self::assertSame('{"pan":"[Filtered]"}', $payload['request']['data']['json']);
    }

    /**
     * @return list<string>
     */
    private function luhnTraceIds(): array
    {
        return [
            '00000000000000000000deadbeefcafe',
            '4111111111111111f7639f97b1a282bb',
            'a4111111111111111f7639f97b1a282b',
            '378282246310005abcdef0123456789a',
            '0af7651916cd43dd8448eb211c80319c',
            '4111111111111111',
            '00f067aa0ba902b7',
        ];
    }

    public function testLuhnValidRunsInTraceIdsStayUntouched(): void
    {
        foreach ($this->luhnTraceIds() as $traceId) {
            $payload = (new PayloadScrubber())->scrub([
                'extra'    => ['trace_id' => $traceId, 'span_id' => $traceId, 'parent_span_id' => $traceId, 'TRACE_ID' => $traceId],
                'tags'     => ['trace_id' => $traceId],
                'contexts' => ['trace' => ['trace_id' => $traceId, 'span_id' => $traceId, 'parent_span_id' => $traceId]],
            ]);

            self::assertSame(['trace_id' => $traceId, 'span_id' => $traceId, 'parent_span_id' => $traceId, 'TRACE_ID' => $traceId], $payload['extra']);
            self::assertSame(['trace_id' => $traceId], $payload['tags']);
            self::assertSame(['trace' => ['trace_id' => $traceId, 'span_id' => $traceId, 'parent_span_id' => $traceId]], $payload['contexts']);
        }
    }

    public function testRandomHexIdsAreNeverMasked(): void
    {
        mt_srand(31);
        $scrubber = new PayloadScrubber();
        for ($i = 0; $i < 20000; ++$i) {
            $traceId = bin2hex(random_bytes(16));
            $spanId  = bin2hex(random_bytes(8));
            $payload = $scrubber->scrub(['tags' => ['trace_id' => $traceId], 'contexts' => ['trace' => ['trace_id' => $traceId, 'span_id' => $spanId]]]);
            self::assertSame(['trace_id' => $traceId], $payload['tags']);
            self::assertSame(['trace' => ['trace_id' => $traceId, 'span_id' => $spanId]], $payload['contexts']);
        }
    }

    public function testIdentifierKeyWithNonStringValueIsStillScrubbed(): void
    {
        $payload = (new PayloadScrubber())->scrub(['extra' => ['trace_id' => 4111111111111111, 'span_id' => ['n' => 'pan 4111111111111111']]]);

        self::assertSame(['trace_id' => '[Filtered]', 'span_id' => ['n' => 'pan [Filtered]']], $payload['extra']);
    }

    public function testNonIdShapedValuesUnderIdentifierKeysAreScrubbed(): void
    {
        $payload = (new PayloadScrubber())->scrub(['extra' => ['trace_id' => 'pan 4111 1111 1111 1111', 'span_id' => 'https://u:p@h/x']]);

        self::assertIsArray($payload['extra']);
        self::assertSame('pan [Filtered]', $payload['extra']['trace_id']);
        self::assertStringNotContainsString('u:p@', (string) $payload['extra']['span_id']);
    }
}
