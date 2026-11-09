<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class SensitiveValueRedactorTest extends TestCase
{
    public function testRedactsSensitiveKeysAndCredentialUris(): void
    {
        $out = SensitiveValueRedactor::redactMap([
            'name'     => 'demo',
            'password' => 's3cret',
            'api_key'  => 'abc',
            'dsn'      => 'mysql://user:pass@db:3306/app',
            'url'      => 'mysql://user:pass@db:3306/app',
            'nested'   => ['token' => 'x', 'ok' => 1],
            'object'   => new stdClass(),
        ]);

        self::assertSame('demo', $out['name']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['password']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['api_key']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['dsn']);
        self::assertSame('mysql://' . SensitiveValueRedactor::FILTERED . '@db:3306/app', $out['url']);
        self::assertSame(SensitiveValueRedactor::FILTERED, $out['nested']['token']);
        self::assertSame(1, $out['nested']['ok']);
        self::assertSame(stdClass::class, $out['object']);
    }

    public function testRedactQueryStringFiltersSecretKeysAndKeepsOthers(): void
    {
        self::assertSame('', SensitiveValueRedactor::redactQueryString(''));
        self::assertSame(
            'page=2&token=[Filtered]&api_key=[Filtered]&user%5Bpassword%5D=[Filtered]&flag&q=hello%20world',
            SensitiveValueRedactor::redactQueryString('page=2&token=abc123&api_key=k&user%5Bpassword%5D=pw&flag&q=hello%20world'),
        );
        self::assertSame(
            'next=' . rawurlencode('https://[Filtered]@db.test/x'),
            SensitiveValueRedactor::redactQueryString('next=' . rawurlencode('https://admin:pw@db.test/x')),
        );
        self::assertSame(
            'pem=[Filtered]',
            SensitiveValueRedactor::redactQueryString('pem=' . rawurlencode('-----BEGIN PRIVATE KEY-----')),
        );
    }

    public function testRedactUrlFiltersQueryAndUserinfoAndKeepsFragment(): void
    {
        self::assertSame('https://demo.test/path', SensitiveValueRedactor::redactUrl('https://demo.test/path'));
        self::assertSame(
            'https://demo.test/reset?token=[Filtered]&x=1#frag',
            SensitiveValueRedactor::redactUrl('https://demo.test/reset?token=s3cr3t&x=1#frag'),
        );
        self::assertSame(
            'https://[Filtered]@demo.test/?access_token=[Filtered]',
            SensitiveValueRedactor::redactUrl('https://user:pw@demo.test/?access_token=abc'),
        );
    }

    public function testRedactUrlFiltersLoginLinkAndSignedUrlParameters(): void
    {
        self::assertSame(
            'https://demo.test/login_check?user=jane%40example.com&expires=1760000000&hash=[Filtered]',
            SensitiveValueRedactor::redactUrl('https://demo.test/login_check?user=jane%40example.com&expires=1760000000&hash=Jm9hB2p0dE5kbz0~abc'),
        );
        self::assertSame(
            'https://demo.test/verify?id=7&_hash=[Filtered]&signature=[Filtered]&sig=[Filtered]&X-Amz-Signature=[Filtered]&expires=9',
            SensitiveValueRedactor::redactUrl('https://demo.test/verify?id=7&_hash=h1&signature=s1&sig=s2&X-Amz-Signature=s3&expires=9'),
        );
    }

    public function testRedactUrlFiltersOAuthCallbackCode(): void
    {
        self::assertSame(
            'https://demo.test/connect/check?code=[Filtered]&state=xyz&country_code=ES&zip_code=28001',
            SensitiveValueRedactor::redactUrl('https://demo.test/connect/check?code=4%2F0AfJohXk-secret&state=xyz&country_code=ES&zip_code=28001'),
        );
        self::assertSame('code=&page=1', SensitiveValueRedactor::redactQueryString('code=&page=1'));
        self::assertSame('code%5B0%5D=[Filtered]', SensitiveValueRedactor::redactQueryString('code%5B0%5D=x'));
    }

    public function testUrlOnlyParameterNamesAreNotFilteredInMaps(): void
    {
        $out = SensitiveValueRedactor::redactMap(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'signature' => 'Jane']);

        self::assertSame(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'signature' => 'Jane'], $out);
    }

    public function testRedactMessageReplacesCredentialShapedSubstrings(): void
    {
        self::assertSame('', SensitiveValueRedactor::redactMessage(''));
        self::assertSame('Undefined index: user_id', SensitiveValueRedactor::redactMessage('Undefined index: user_id'));
        self::assertSame(
            'Stripe said no to [Filtered] (Bearer [Filtered]) for password=[Filtered], db mysql://app:[Filtered]@db/app',
            SensitiveValueRedactor::redactMessage('Stripe said no to sk_live_abcdefghijklmnop (Bearer abcdefgh12345) for password=hunter2, db mysql://app:s3cr3t@db/app'),
        );
        self::assertSame(
            '[Filtered] [Filtered] [Filtered] [Filtered] [Filtered] [Filtered] [Filtered]',
            SensitiveValueRedactor::redactMessage('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N AKIAABCDEFGHIJKLMNOP ghp_abcdefghijklmnopqrstuvwxyz0123456789 github_pat_abcdefghijklmnopqrstuvwxyz0123 xoxb-1234567890-abc AIzaSyA1234567890abcdefghijklmnopqrstuv sk-ant-api03-abcdefghijklmnopqrstuvwxyz'),
        );
        self::assertSame('{"api_key": "[Filtered]"}', SensitiveValueRedactor::redactMessage('{"api_key": "abc123"}'));
    }

    public function testContextStringsGetTheMessagePatterns(): void
    {
        self::assertSame(['note' => 'sent Bearer [Filtered]'], SensitiveValueRedactor::redactMap(['note' => 'sent Bearer abcdefgh12345']));
    }

    public function testRedactValueSummarisesStringablesAndResources(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'postgres://app:pw@db/x';
            }
        };
        self::assertSame('postgres://[Filtered]@db/x', SensitiveValueRedactor::redactValue($stringable));

        $resource = fopen('php://memory', 'r');
        self::assertSame(SensitiveValueRedactor::FILTERED, SensitiveValueRedactor::redactValue($resource));
        fclose($resource);
    }
}
