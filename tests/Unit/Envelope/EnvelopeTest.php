<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Envelope;

use Nowo\Beacon\Envelope\Envelope;
use Nowo\Beacon\Envelope\SendOptions;
use Nowo\Beacon\Exception\ConfigurationException;
use Nowo\Beacon\Exception\EnvelopeEncodingException;
use PHPUnit\Framework\TestCase;

use const INF;

final class EnvelopeTest extends TestCase
{
    public function testEncodesThreeLinesWithBundleFlags(): void
    {
        $envelope = new Envelope(['event_id' => 'abc'], ['type' => 'event'], ['url' => 'https://x/y', 'text' => "é\xB1"]);

        self::assertSame("{\"event_id\":\"abc\"}\n{\"type\":\"event\"}\n{\"url\":\"https://x/y\",\"text\":\"é\u{FFFD}\"}\n", $envelope->encode());
        self::assertSame('abc', $envelope->getEventId());
        self::assertSame('event', $envelope->getType());
        self::assertSame(['a' => 1], $envelope->withPayload(['a' => 1])->payload);
    }

    public function testMissingIdsAndEncodingFailure(): void
    {
        $envelope = new Envelope(['event_id' => 5], ['type' => []], ['v' => INF]);
        self::assertSame('', $envelope->getEventId());
        self::assertSame('', $envelope->getType());

        $this->expectException(EnvelopeEncodingException::class);
        $envelope->encode();
    }

    public function testSendOptionsFromArray(): void
    {
        $options = SendOptions::fromArray(['user' => true, 'client' => true, 'stack_vars' => true, 'os' => false]);
        self::assertTrue($options->user);
        self::assertTrue($options->client);
        self::assertTrue($options->stackVars);
        self::assertFalse($options->os);
        self::assertTrue($options->runtime);

        $defaults = SendOptions::fromArray([]);
        self::assertFalse($defaults->user);
        self::assertFalse($defaults->client);
        self::assertTrue($defaults->stacktrace);
    }

    public function testSendOptionsRejectUnknownKeysAndNonBooleans(): void
    {
        try {
            SendOptions::fromArray(['users' => true]);
            self::fail('Expected exception');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('Unknown "send" option "users"', $exception->getMessage());
        }

        $this->expectExceptionMessage('The "send.user" option must be a boolean.');
        SendOptions::fromArray(['user' => 'yes']);
    }
}
