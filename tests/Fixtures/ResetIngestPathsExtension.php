<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Nowo\Beacon\Ingest\IngestPathResolver;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Forgets the process-wide ingest path cache before every test, so no test depends on what another one taught it.
 */
final class ResetIngestPathsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                IngestPathResolver::shared()->reset();
            }
        });
    }
}
