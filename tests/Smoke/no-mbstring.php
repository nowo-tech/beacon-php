<?php

declare(strict_types=1);

/*
 * Smoke check for PHP without ext-mbstring (CI job `no-mbstring`). PHPUnit itself needs mbstring, so this plain
 * script proves that symfony/polyfill-mbstring is enough: a capture with a long UTF-8 tag builds and sends an
 * envelope, and the tag is truncated on a character boundary.
 */

use Nowo\Beacon\Client;
use Nowo\Beacon\Options;
use Nowo\Beacon\Transport\TransportInterface;
use Nowo\Beacon\Transport\TransportResult;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (extension_loaded('mbstring')) {
    fwrite(\STDERR, "ext-mbstring is loaded: this check must run without it.\n");
    exit(1);
}

$transport = new class implements TransportInterface {
    /** @var list<string> */
    public array $bodies = [];

    public function send(string $envelopeBody): TransportResult
    {
        $this->bodies[] = $envelopeBody;

        return new TransportResult(true, 202);
    }

    public function flush(float $timeout): bool
    {
        return true;
    }
};

$client = new Client(Options::fromArray(['dsn' => 'https://pk:client-secret@b.example/1']), $transport);
$client->setTag('city', str_repeat('ñ', 500));
$id = $client->captureMessage('smoke without mbstring');

if ($id === null || count($transport->bodies) !== 1) {
    fwrite(\STDERR, "No envelope was sent.\n");
    exit(1);
}

$body = $transport->bodies[0];
if (!str_contains($body, 'smoke without mbstring')) {
    fwrite(\STDERR, "The envelope does not carry the message.\n");
    exit(1);
}
foreach (explode("\n", trim($body)) as $line) {
    if (json_decode($line, true) === null) {
        fwrite(\STDERR, "An envelope line is not valid JSON (broken UTF-8 truncation?).\n");
        exit(1);
    }
}

echo "OK: envelope sent without ext-mbstring.\n";
