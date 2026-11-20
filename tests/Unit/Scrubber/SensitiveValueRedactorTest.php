<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use Nowo\Beacon\Context\DatabaseExceptionContext;
use Nowo\Beacon\Scrubber\IpStripper;
use Nowo\Beacon\Scrubber\PayloadScrubber;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Stringable;

use function array_keys;
use function bin2hex;
use function fclose;
use function fopen;
use function ini_set;
use function intdiv;
use function json_encode;
use function microtime;
use function random_bytes;
use function str_contains;
use function str_repeat;
use function strlen;
use function strrpos;
use function strtoupper;
use function substr;

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
        $out = SensitiveValueRedactor::redactMap(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'key' => 'k', 'reset' => 'r']);

        self::assertSame(['hash' => 'abc', 'code' => 'E42', 'sig' => 'v1', 'key' => 'k', 'reset' => 'r'], $out);
        // `signature` is a credential key everywhere (BeaconBundle 1.19.2 rule).
        self::assertSame(['signature' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactMap(['signature' => 'Jane']));
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

    /**
     * SEC-004 Appendix CH, L1: inputs that leaked before 1.19.2 (all values fake).
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function leakingMessages(): iterable
    {
        // Secret-shaped values are generated at runtime so no credential-looking literal is committed.
        $v = static fn (string $prefix = 'Fk'): string => $prefix . bin2hex(random_bytes(8));
        $a = $v();
        yield 'env-style DB_PASSWORD' => ['Failed to boot: DB_PASSWORD=' . $a . ' is wrong', [$a]];
        $a = $v();
        yield 'env-style AWS_SECRET_ACCESS_KEY' => ['AWS_SECRET_ACCESS_KEY=' . $a . ' rejected', [$a]];
        $a = $v();
        yield 'env-style STRIPE_API_KEY' => ['STRIPE_API_KEY=' . $a . ' invalid', [$a]];
        $a = $v();
        yield 'PHP array export' => ["Options were ['password' => '" . $a . "', 'user' => 'app']", [$a]];
        $a = $v('horse');
        $b = $v('battery');
        yield 'JSON pair with spaces' => ['Payload {"password": "correct ' . $a . ' ' . $b . '"}', [$a, $b]];
        $a = $v();
        yield 'password with ;' => ['login failed for password=Fa;' . $a, [$a]];
        $a = $v();
        yield 'passphrase' => ['passphrase=' . $a, [$a]];
        $a = $v();
        yield 'pass=' => ['smtp pass=' . $a . ' refused', [$a]];
        $a = $v();
        yield 'pwd / pin / otp / cvv' => ['pwd=' . $a . ' pin: 4321 otp=987654 cvv=123', [$a, '4321', '987654', '=123']];
        $a = $v();
        yield 'URL password with /' => ['Cannot connect to mysql://app:Fk/' . $a . '@db.internal:3306/app', [$a]];
        $a = $v();
        yield 'URL password with @' => ['Cannot connect to redis://default:Fk@' . $a . '@cache:6379', [$a]];
        $a = $v();
        yield 'Beacon DSN' => ['Bad DSN https://pubFAKEkey:' . $a . '@errors.example/1', [$a]];
        $a = 'bwk_' . bin2hex(random_bytes(24));
        yield 'Beacon worker key' => ['Header was ' . $a, [$a]];
        $a = $v();
        yield 'X-Beacon-Auth value' => ['Beacon beacon_key=pubFAKE, beacon_secret=' . $a, [$a]];
        // Re-verification of PR #28: N1 regression (filtered on 1.19.1) and camelCase names.
        $a = $v();
        yield 'clientSecret=' => ['OAuth error, clientSecret=' . $a . ' rejected', [$a]];
        $a = $v();
        yield 'clientsecret:' => ['clientsecret: ' . $a, [$a]];
        $a = $v();
        yield 'JSON clientSecret' => ['Body {"clientSecret":"' . $a . '"}', [$a]];
        $a = $v();
        yield 'camelCase dbPassword' => ['Config dbPassword=' . $a, [$a]];
        $a = $v();
        yield 'camelCase JSON apiToken' => ['Body {"apiToken":"' . $a . '"}', [$a]];
        $a = $v();
        yield 'URL password, empty user' => ['Cannot connect to redis://:' . $a . '@cache:6379', [$a]];
        $a = $v();
        yield 'JSON value with an escaped quote' => ['Body {"password":"ab\\"' . $a . '"}', [$a]];
        yield 'Amex, 15 digits' => ['Card 378282246310005 declined', ['378282246310005']];
        $a = $v();
        yield 'all-caps run myAPIToken' => ['Config myAPIToken=' . $a, [$a]];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('leakingMessages')]
    public function testRedactMessageCoversEnvStyleQuotedAndBeaconCredentials(string $message, array $secrets): void
    {
        $out = SensitiveValueRedactor::redactMessage($message);

        foreach ($secrets as $secret) {
            self::assertFalse(str_contains($out, $secret), $secret . ' leaked in ' . $out);
        }
        self::assertStringContainsString(SensitiveValueRedactor::FILTERED, $out);
    }

    public function testRedactMessageKeepsHarmlessText(): void
    {
        foreach ([
            'passenger=Bob shipping=fast discard=yes',
            'GET https://example.com:8443/users/@me failed',
            'Order 42 failed: stock=0',
            'beacon_key=pubFAKE is the public key',
        ] as $message) {
            self::assertSame($message, SensitiveValueRedactor::redactMessage($message));
        }

        self::assertSame('Cannot connect to postgresql://app:[Filtered]@db:5432/app', SensitiveValueRedactor::redactMessage('Cannot connect to postgresql://app:FAKEpgpw@db:5432/app'));
    }

    public function testRedactMessageFiltersLuhnValidCardNumbersOnly(): void
    {
        self::assertSame('Charge failed for card [Filtered] cvv=[Filtered]', SensitiveValueRedactor::redactMessage('Charge failed for card 4111111111111111 cvv=123'));
        self::assertSame('Card [Filtered] declined', SensitiveValueRedactor::redactMessage('Card 5500-0000-0000-0004 declined'));
        // Not Luhn-valid, or not a card prefix (millisecond timestamps, order ids): kept.
        foreach (['Order 4111111111111112 failed', 'at 1793553024389 ms', 'id 9111111111111111'] as $message) {
            self::assertSame($message, SensitiveValueRedactor::redactMessage($message));
        }
    }

    public function testRedactMapCoversShortCredentialKeys(): void
    {
        $out = SensitiveValueRedactor::redactMap([
            'db_pass'     => 'FAKE-2', 'pwd' => 'FAKE-3', 'pass' => 'FAKE-4', 'pin' => '4321', 'otp' => '987654', 'cvv' => '123',
            'card_number' => '4111111111111111', 'iban' => 'ES9121000418450200051332', 'cookie' => 'PHPSESSID=FAKEsess',
            'session_id'  => 'FAKEsess2', 'signature' => 'FAKEsig', 'private' => 'FAKE-5', 'passphrase' => 'FAKE-6',
            'passenger'   => 'Bob', 'shipping' => 'fast', 'discard' => 'yes', 'mapping' => 'm',
            'cardNumber'  => '4111111111111111', 'userPin' => '4321', 'dbPass' => 'FAKE-7', 'creditCard' => 'FAKE-8',
            'isPinned'    => true, 'PASSENGER' => 'Ann', 'OTPCode' => '987654',
        ]);

        foreach (['db_pass', 'pwd', 'pass', 'pin', 'otp', 'cvv', 'card_number', 'iban', 'cookie', 'session_id', 'signature', 'private', 'passphrase', 'cardNumber', 'userPin', 'dbPass', 'creditCard', 'OTPCode'] as $key) {
            self::assertSame(SensitiveValueRedactor::FILTERED, $out[$key], $key);
        }
        self::assertSame(['Bob', 'fast', 'yes', 'm', true, 'Ann'], [$out['passenger'], $out['shipping'], $out['discard'], $out['mapping'], $out['isPinned'], $out['PASSENGER']]);
    }

    public function testRedactQueryStringCoversShortCredentialParameters(): void
    {
        $out = SensitiveValueRedactor::redactQueryString('pass=FAKEqp&user=bob&pwd=FAKEqp2&key=FAKEgmapskey&session=FAKEsessq&reset=FAKEreset&sid=FAKEsid&page=2');

        self::assertSame('pass=[Filtered]&user=bob&pwd=[Filtered]&key=[Filtered]&session=[Filtered]&reset=[Filtered]&sid=[Filtered]&page=2', $out);
        self::assertFalse(str_contains((string) json_encode($out), 'FAKE'));
    }

    /**
     * Re-check of PR #28, N4: a huge quoted value after a secret name must not exhaust PCRE and leak the whole message.
     */
    public function testHugeQuotedValuesDoNotDisableRedaction(): void
    {
        $first  = 'Fk' . bin2hex(random_bytes(8));
        $second = 'bwk_' . bin2hex(random_bytes(24));
        $huge   = str_repeat('x', 1 << 20);

        foreach ([
            'DB_PASSWORD=' . $first . ' cfg {"password":"' . $huge . '"} sent ' . $second,
            'DB_PASSWORD=' . $first . " cfg ['password' => '" . $huge . "'] sent " . $second,
            'DB_PASSWORD=' . $first . ' cfg {"password":"' . $huge . ' unterminated, sent ' . $second,
            'DB_PASSWORD=' . $first . ' -----BEGIN RSA PRIVATE KEY-----' . $huge . ' sent ' . $second,
            'redis://u:' . $first . str_repeat('@x', 1 << 18) . ' sent ' . $second,
        ] as $index => $message) {
            $out = SensitiveValueRedactor::redactMessage($message);

            self::assertNotSame(SensitiveValueRedactor::REDACTION_FAILED, $out, (string) $index);
            self::assertFalse(str_contains($out, $first), $index . ': first secret leaked');
            self::assertFalse(str_contains($out, $second), $index . ': second secret leaked');
        }
    }

    /**
     * N4: when PCRE fails (backtrack limit, JIT off so the limit applies), redaction fails closed.
     */
    #[RunInSeparateProcess]
    public function testPcreErrorsFailClosed(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        $secret = 'Fk' . bin2hex(random_bytes(8));

        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactMessage('DB_PASSWORD=' . $secret . ' and more text'));
        self::assertSame(['user' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactMap(['user' => $secret]));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactValue('mysql://app:' . $secret . '@db/app'));
        self::assertStringNotContainsString($secret, SensitiveValueRedactor::redactQueryString('page=' . $secret));
    }

    /**
     * Re-check of PR #28, N5: repeated secret-like names must not make redaction quadratic.
     */
    public function testRepeatedSecretNamesRedactInLinearTime(): void
    {
        foreach (['aPassword=', 'aPassword ', '_password', 'aToken', '-secret', 'a.'] as $unit) {
            $first  = 'Fk' . bin2hex(random_bytes(8));
            $second = 'Fk' . bin2hex(random_bytes(8));
            $prefix = 'DB_PASSWORD=' . $first . ' ' . str_repeat($unit, intdiv(30000, strlen($unit))) . ' token=' . $second . ' ';
            $start  = microtime(true);
            $out    = SensitiveValueRedactor::redactMessage($prefix . str_repeat($unit, intdiv(1 << 20, strlen($unit))));

            self::assertLessThan(1.0, microtime(true) - $start, $unit);
            self::assertFalse(str_contains($out, $first), $unit . ': first secret leaked');
            self::assertFalse(str_contains($out, $second), $unit . ': second secret leaked');
            self::assertStringEndsWith(SensitiveValueRedactor::TRUNCATED, $out);
        }
    }

    public function testLongMessagesAreCutOnAUtf8BoundaryAndTheTailDropped(): void
    {
        $tail = 'Fk' . bin2hex(random_bytes(8));
        $max  = SensitiveValueRedactor::MAX_MESSAGE_LENGTH;

        // The cut falls inside a 64 KiB run: the last 4 KiB of the head are dropped too.
        self::assertSame(str_repeat('a', $max - 4096) . SensitiveValueRedactor::TRUNCATED, SensitiveValueRedactor::redactMessage(str_repeat('a', $max - 1) . 'é' . $tail));
        // The cut falls inside a word: the word is dropped, up to the whitespace before it.
        self::assertSame(str_repeat('a', $max - 10) . ' ' . SensitiveValueRedactor::TRUNCATED, SensitiveValueRedactor::redactMessage(str_repeat('a', $max - 10) . ' ' . str_repeat('é', 20) . $tail));
        // The cut falls on whitespace: nothing straddles it, the head is kept whole.
        self::assertSame(str_repeat('a', $max) . SensitiveValueRedactor::TRUNCATED, SensitiveValueRedactor::redactMessage(str_repeat('a', $max) . ' ' . $tail));
        self::assertSame($message = str_repeat('b', $max), SensitiveValueRedactor::redactMessage($message));
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
        self::assertSame(['0' => 'a', 'k' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactValue(['a', 'k' => new class implements Stringable {
            public function __toString(): string
            {
                return '-----BEGIN PRIVATE KEY-----abc';
            }
        }]));

        $resource = fopen('php://memory', 'r');
        self::assertSame(SensitiveValueRedactor::FILTERED, SensitiveValueRedactor::redactValue($resource));
        fclose($resource);
    }

    public function testUserinfoUrlsStillGetTheMessagePatterns(): void
    {
        $secret = 'Fk' . bin2hex(random_bytes(8));

        self::assertSame(
            'mysql://[Filtered]@db/app?password=[Filtered] Bearer [Filtered]',
            SensitiveValueRedactor::redactValue('mysql://app:pw@db/app?password=' . $secret . ' Bearer ' . $secret),
        );
    }

    public function testPrivateKeyWithoutItsOwnEndMarkerIsFilteredToTheEnd(): void
    {
        $secret = 'Fk' . bin2hex(random_bytes(8));
        $out    = SensitiveValueRedactor::redactMessage('key -----BEGIN PRIVATE KEY-----' . $secret . "\n-----END CERTIFICATE-----\n" . $secret);

        self::assertSame('key [Filtered]', $out);
        self::assertSame(
            'a [Filtered] b',
            SensitiveValueRedactor::redactMessage("a -----BEGIN EC PRIVATE KEY-----\n" . $secret . "\n-----END EC PRIVATE KEY----- b"),
        );
    }

    public function testSchemesLongerThanThirtyTwoCharactersAreStillRedacted(): void
    {
        $secret = 'Fk' . bin2hex(random_bytes(8));

        self::assertStringNotContainsString($secret, SensitiveValueRedactor::redactMessage('x ' . str_repeat('a', 40) . '://u:' . $secret . '@host/'));
        self::assertStringNotContainsString($secret, SensitiveValueRedactor::redactMessage('x 1https://u:' . $secret . '@host/'));
    }

    /**
     * The patterns are byte-oriented: invalid UTF-8 never makes PCRE fail, and secrets around invalid bytes are
     * still redacted.
     */
    public function testInvalidUtf8IsRedactedByteWise(): void
    {
        $secret  = 'Fk' . bin2hex(random_bytes(8));
        $invalid = "\xC3\x28\xFF\xFE\x80";

        $out = SensitiveValueRedactor::redactMessage($invalid . ' password=' . $secret . ' ' . $invalid . ' sk_live_' . $secret . $invalid);
        self::assertSame($invalid . ' password=[Filtered] ' . $invalid . ' [Filtered]' . $invalid, $out);

        $map = SensitiveValueRedactor::redactMap(["k\xFF" => 'token=' . $secret . $invalid . ' ok', "password\xFF" => $invalid]);
        self::assertSame(["k\xFF" => 'token=[Filtered] ok', "password\xFF" => SensitiveValueRedactor::FILTERED], $map);

        self::assertSame('a=' . $invalid . '&token=[Filtered]', SensitiveValueRedactor::redactQueryString('a=' . $invalid . '&token=' . $secret));
        self::assertSame('https://[Filtered]@h/' . $invalid, SensitiveValueRedactor::redactUrl("https://u:\xFF" . $secret . '@h/' . $invalid));
    }

    public function testNoPatternUsesTheUtf8Modifier(): void
    {
        $constants = (new ReflectionClass(SensitiveValueRedactor::class))->getConstants();
        $patterns  = [...array_keys($constants['SECRET_PATTERNS']), $constants['SENSITIVE_KEY'], $constants['CARD_PATTERN'], $constants['URI_USERINFO'], $constants['SENSITIVE_URL_PARAMETER']];

        foreach ($patterns as $pattern) {
            self::assertIsString($pattern);
            self::assertStringNotContainsString('u', substr($pattern, (int) strrpos($pattern, $pattern[0]) + 1), $pattern);
        }
    }

    public function testInvalidUtf8AtTheCutIsCutWhereItIs(): void
    {
        $tail = 'Fk' . bin2hex(random_bytes(8));
        $out  = SensitiveValueRedactor::redactMessage(str_repeat('a', 100) . str_repeat("\x80", SensitiveValueRedactor::MAX_MESSAGE_LENGTH) . $tail);

        // 4 KiB of the straddling run dropped; the end then moves back at most three bytes, not down to the start.
        self::assertSame(SensitiveValueRedactor::MAX_MESSAGE_LENGTH - 4096 - 3 + strlen(SensitiveValueRedactor::TRUNCATED), strlen($out));
        self::assertStringEndsWith("\x80" . SensitiveValueRedactor::TRUNCATED, $out);
        self::assertStringNotContainsString($tail, $out);
    }

    /**
     * Audit XF-1: a secret that straddles the 64 KiB cut must not survive in part. Every sample is moved across the
     * cut at every offset.
     */
    public function testSecretsStraddlingTheCutAreDroppedAtEveryOffset(): void
    {
        $max  = SensitiveValueRedactor::MAX_MESSAGE_LENGTH;
        $core = bin2hex(random_bytes(20));
        $a    = substr($core, 0, 20);
        $b    = substr($core, 20);

        $samples = [
            'mysql://root:' . $a . '@db/app',
            'AIza' . $a . substr($b, 0, 15),
            'ghp_' . $a . $b,
            'AKIA' . strtoupper(substr($a, 0, 16)),
            'eyJ' . $a . '.eyJ' . $b . '.' . $a,
            'sk_live_' . $a,
            'github_pat_' . $a . $b,
            'xoxb-' . $a,
            'sk-ant-' . $a,
            'bwk_' . $a,
            'Bearer ' . $a,
            'password=' . $a,
            'password="' . $a . ' ' . $b . '"',
            "'token' => '" . $a . ' ' . $b . "'",
            "-----BEGIN PRIVATE KEY-----\n" . $a . "\n" . $b . "\n-----END PRIVATE KEY-----",
        ];
        $filler = str_repeat('lorem ', intdiv($max, 6) + 1);

        foreach ($samples as $sample) {
            for ($offset = 1, $length = strlen($sample); $offset < $length; ++$offset) {
                $out = SensitiveValueRedactor::redactMessage(substr($filler, 0, $max - $offset - 1) . ' ' . $sample . ' tail');

                foreach ([$a, $b] as $secret) {
                    for ($i = 0; $i + 6 <= strlen($secret); ++$i) {
                        self::assertStringNotContainsString(substr($secret, $i, 6), substr($out, $max - 4200), $sample . ' @' . $offset);
                    }
                }
            }
        }
    }

    public function testUnclosedQuotedValuesAreFilteredToTheEnd(): void
    {
        self::assertSame('{"password": "[Filtered]', SensitiveValueRedactor::redactMessage('{"password": "two words'));
        self::assertSame("['token' => '[Filtered]", SensitiveValueRedactor::redactMessage("['token' => 'two words"));
        self::assertSame('{"password": "[Filtered]', SensitiveValueRedactor::redactMessage('{"password": "ends \\'));
    }

    /**
     * Audit XF-2: a `[Filtered]` left by an earlier rule inside a value does not stop the name=value rule.
     */
    public function testRestOfAValueAfterAnEarlierFilterIsFiltered(): void
    {
        $secret = 'Fk' . bin2hex(random_bytes(8));

        self::assertSame('password=[Filtered] ok', SensitiveValueRedactor::redactMessage('password=sk_live_' . bin2hex(random_bytes(8)) . '.Z' . $secret . ' ok'));
        // `]` ends an unquoted value; the `[` inside it does not.
        self::assertSame('password=[Filtered]] ok', SensitiveValueRedactor::redactMessage('password=ab[' . $secret . '] ok'));
        // `=>` is one separator: a filtered `'…' => '[Filtered]'` pair is not filtered again from its `=`.
        self::assertSame("['password' => '[Filtered]', 'user' => 'app']", SensitiveValueRedactor::redactMessage("['password' => '" . $secret . "', 'user' => 'app']"));
        self::assertSame('password=[Filtered], ok', SensitiveValueRedactor::redactMessage('password=[Filtered], ok'));
    }

    /**
     * Audit XF-3: URI userinfo whose password contains `@`.
     */
    public function testUserinfoPasswordWithAtSigns(): void
    {
        self::assertSame('mysql://[Filtered]@db/app', SensitiveValueRedactor::redactValue('mysql://root:p@ss@db/app'));
        self::assertSame('mysql://[Filtered]@db/app?x=1', SensitiveValueRedactor::redactUrl('mysql://root:p@s@s@db/app?x=1'));
        self::assertSame('https://demo.test/a@b', SensitiveValueRedactor::redactValue('https://demo.test/a@b'));
    }

    /**
     * Worst-case shapes at the maximum length redact with the default PCRE limits, JIT on.
     */
    public function testWorstCaseInputsAtTheMaximumLengthDoNotHitTheDefaultLimits(): void
    {
        $this->assertWorstCaseInputsRedact();
    }

    /**
     * The same without JIT (pcre.jit is read when a pattern is compiled, so a fresh process is needed).
     */
    #[RunInSeparateProcess]
    public function testWorstCaseInputsAtTheMaximumLengthDoNotHitTheDefaultLimitsWithoutJit(): void
    {
        ini_set('pcre.jit', '0');
        $this->assertWorstCaseInputsRedact();
    }

    /**
     * Huge messages that hit `pcre.backtrack_limit` (lowered, JIT off so the limit applies): the original text is
     * never returned, in the redactor and in every other scrubbing step that runs a regex over user data.
     */
    #[RunInSeparateProcess]
    public function testBacktrackLimitFailsClosedEverywhere(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        $secret = 'Fk' . bin2hex(random_bytes(8));
        $huge   = 'DB_PASSWORD=' . $secret . ' ' . str_repeat('x y ', 1 << 14);

        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactMessage($huge));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactMessage('password=' . $secret));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactValue($huge));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactUrl('https://u:' . $secret . '@h/'));
        self::assertTrue(SensitiveValueRedactor::isSensitiveKey('harmless'));
        self::assertSame(['page' => SensitiveValueRedactor::FILTERED], SensitiveValueRedactor::redactMap(['page' => $secret]));
        self::assertSame('page=[Filtered]', SensitiveValueRedactor::redactQueryString('page=' . $secret));

        self::assertTrue(PayloadScrubber::isSensitiveKey('harmless'));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, PayloadScrubber::maskCards('card 4111111111111111 ' . $secret));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, PayloadScrubber::scrubText($huge));
        self::assertSame(SensitiveValueRedactor::REDACTION_FAILED, DatabaseExceptionContext::scrubSql("insert into t values ('" . $secret . "')"));
        self::assertTrue(IpStripper::isClientAddressHeader('X-Harmless'));

        // Back to the default limit in the same process: the same calls redact normally again.
        ini_set('pcre.backtrack_limit', '1000000');
        self::assertFalse(SensitiveValueRedactor::isSensitiveKey('harmless'));
        self::assertFalse(IpStripper::isClientAddressHeader('X-Harmless'));
        self::assertSame('password=[Filtered]', SensitiveValueRedactor::redactMessage('password=' . $secret));
    }

    /**
     * Every secret pattern still redacts after the possessive rewrite (one sample per rule).
     */
    public function testEverySecretPatternStillRedacts(): void
    {
        $secret = 'Fk' . bin2hex(random_bytes(16));

        foreach ([
            '-----BEGIN RSA PRIVATE KEY-----' . $secret . '-----END RSA PRIVATE KEY-----',
            'smtp://user:' . $secret . '@mail',
            'eyJ' . $secret . '.eyJ' . $secret . '.' . $secret,
            'Basic ' . $secret,
            'rk_test_' . $secret,
            'github_pat_' . $secret,
            'xoxp-' . $secret,
            'sk-' . $secret,
            'bwk_' . $secret,
            'api-key: ' . $secret,
            'client_secret="' . $secret . '"',
            "'token' => '" . $secret . "'",
            'password="' . $secret,
            "{'pwd': '" . $secret,
        ] as $message) {
            self::assertStringNotContainsString($secret, SensitiveValueRedactor::redactMessage('x ' . $message . ' y'), $message);
        }

        self::assertSame('x [Filtered] y', SensitiveValueRedactor::redactMessage('x ASIAABCDEFGHIJKLMNOP y'));
        self::assertSame('x [Filtered] y', SensitiveValueRedactor::redactMessage('x ghs_' . str_repeat('a', 36) . ' y'));
        self::assertSame('x [Filtered] y', SensitiveValueRedactor::redactMessage('x AIza' . str_repeat('b', 35) . ' y'));
        self::assertSame('ghp_' . str_repeat('a', 36) . '_x', SensitiveValueRedactor::redactMessage('ghp_' . str_repeat('a', 36) . '_x'));
    }

    private function assertWorstCaseInputsRedact(): void
    {
        $length = SensitiveValueRedactor::MAX_MESSAGE_LENGTH;
        foreach ([
            '-----BEGIN PRIVATE KEY-----' . str_repeat('-x', $length),
            str_repeat('-', $length),
            'redis://u:' . str_repeat('@', $length),
            str_repeat('a', $length - 10) . '://u:p@h',
            'password="' . str_repeat('\\"', $length),
            "password='" . str_repeat("\\'", $length),
            str_repeat('4 ', $length),
            str_repeat('aB', $length),
            'Bearer ' . str_repeat('a', $length),
            'ghp_' . str_repeat('a', $length) . '_',
        ] as $index => $message) {
            self::assertNotSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactMessage($message), (string) $index);
            self::assertNotSame(SensitiveValueRedactor::REDACTION_FAILED, SensitiveValueRedactor::redactValue($message), (string) $index);
        }
        self::assertFalse(IpStripper::isClientAddressHeader(str_repeat('A', 8192)));
        self::assertNotSame(SensitiveValueRedactor::REDACTION_FAILED, DatabaseExceptionContext::scrubSql("insert into t values ('" . str_repeat('\\\'', 1 << 17) . "')"));
    }
}
