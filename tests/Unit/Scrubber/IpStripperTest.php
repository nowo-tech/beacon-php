<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use Nowo\Beacon\Scrubber\IpStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpStripperTest extends TestCase
{
    public function testRemovesEveryKnownIpLocation(): void
    {
        $request = [
            'client'  => ['ip' => '198.51.100.7', 'user_agent' => 'ua'],
            'env'     => ['REMOTE_ADDR' => '1.2.3.4', 'remote_addr' => '1.2.3.4', 'SERVER_NAME' => 'x'],
            'headers' => ['X-Forwarded-For' => '1.2.3.4', 'CloudFront-Viewer-Address' => '1.2.3.4', 'user-agent' => 'ua', 'accept' => '*/*'],
        ];

        $payload = (new IpStripper())->strip([
            'user'     => ['id' => '1', 'ip_address' => '{{auto}}'],
            'request'  => $request,
            'contexts' => ['request' => $request, 'os' => ['name' => 'Linux']],
            'extra'    => ['http' => ['client' => ['ip' => '198.51.100.7', 'user_agent' => 'ua']], 'list' => ['a', '{{auto}}', 'b'], 'auto' => '{{auto}}'],
        ]);

        self::assertSame(['id' => '1'], $payload['user']);
        self::assertSame(['user_agent' => 'ua'], $payload['request']['client']);
        self::assertSame(['SERVER_NAME' => 'x'], $payload['request']['env']);
        self::assertSame(['user-agent' => 'ua', 'accept' => '*/*'], $payload['request']['headers']);
        self::assertSame($payload['request'], $payload['contexts']['request']);
        self::assertSame(['user_agent' => 'ua'], $payload['extra']['http']['client']);
        self::assertSame(['a', 'b'], $payload['extra']['list']);
        self::assertArrayNotHasKey('auto', $payload['extra']);
    }

    public function testLeavesPayloadWithoutIpUntouched(): void
    {
        $payload = ['message' => 'm', 'contexts' => ['os' => []], 'extra' => ['http' => ['route' => 'r']]];

        self::assertSame($payload, (new IpStripper())->strip($payload));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function headers(): iterable
    {
        yield 'x-real-ip' => ['X-Real-IP', true];
        yield 'forwarded' => ['Forwarded', true];
        yield 'camel socket ip' => ['X-Azure-SocketIP', true];
        yield 'remote addr' => ['X-Remote-Addr', true];
        yield 'via' => ['Via', true];
        yield 'joined' => ['x-clientip', true];
        yield 'fastly' => ['Fastly-Client-IP', true];
        yield 'cf pseudo' => ['CF-Pseudo-IPv4', true];
        yield 'user agent' => ['User-Agent', false];
        yield 'accept' => ['Accept-Language', false];
        yield 'host' => ['Host', false];
    }

    #[DataProvider('headers')]
    public function testClientAddressHeaderDetection(string $name, bool $expected): void
    {
        self::assertSame($expected, IpStripper::isClientAddressHeader($name));
    }
}
